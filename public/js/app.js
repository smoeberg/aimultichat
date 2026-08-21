class MultiChatApp {
    constructor(options = {}) {
        this.chatId = options.chatId || 0;
        this.csrfToken = options.csrfToken || '';
        this.defaultBot = options.defaultBot || '';
        this.messageContainer = document.getElementById('messageContainer');
        this.chatList = document.getElementById('chatList');
        this.errorContainer = document.getElementById('errorContainer');
        this.loader = document.getElementById('loader');
        this.form = document.getElementById('messageForm');
        this.newChatBtn = document.getElementById('newChatBtn');
        this.botSelect = document.getElementById('botSelect');
        this.githubRepo = document.getElementById('githubRepo');
        
        this.isLoading = false;
        this.requestIdCounter = 0;
    }
    
    async init() {
        await this.refreshChats();
        if (this.chatId > 0) {
            await this.loadChat(this.chatId);
        }
        
        this.setupEventListeners();
        this.setupCopyProtection();
        this.setupTemplates();
    }
    
    setupEventListeners() {
        if (this.form) {
            this.form.addEventListener('submit', (e) => this.handleSubmit(e));
        }
        if (this.newChatBtn) {
            this.newChatBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.createNewChat();
            });
        }
    }
    
    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, (m) => map[m]);
    }
    
    /**
     * Generate a unique request ID for AI responses
     */
    generateRequestId() {
        this.requestIdCounter++;
        return 'req_' + Date.now() + '_' + this.requestIdCounter + '_' + Math.random().toString(36).substr(2, 8);
    }
    
    /**
     * Setup copy protection for AI-generated content
     * Adds disclaimer prefix when copying AI responses
     */
    setupCopyProtection() {
        document.addEventListener('copy', (e) => {
            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) return;

            const range = selection.getRangeAt(0);
            const aiResponse = range.startContainer.closest?.('.ai-response');
            
            if (aiResponse) {
                const requestId = aiResponse.dataset.requestId;
                const provider = aiResponse.dataset.provider;
                const model = aiResponse.dataset.model;
                const timestamp = aiResponse.dataset.timestamp;
                const content = aiResponse.querySelector('.ai-content')?.textContent || '';

                if (!content) return;

                const markedText = `[AI-GENERERET - Eira AI via ${provider}]
` + 
                              `Request ID: ${requestId}
` + 
                              `Dato: ${new Date(timestamp).toLocaleString('da-DK')}
` + 
                              `${'='.repeat(50)}
\n` + 
                              content;

                e.clipboardData.setData('text/plain', markedText);

                // HTML version with metadata
                const html = `<div data-ai-generated="true" data-request-id="${requestId}" data-provider="${provider}">
                    <div style="padding:8px;background:#f0f4ff;border-left:4px solid #3b82f6;margin-bottom:8px;font-size:12px;color:#1e40af;">
                        <strong>🤖 AI-GENERERET - Eira AI</strong><br>
                        Request ID: ${requestId} · ${new Date(timestamp).toLocaleString('da-DK')}
                    </div>
                    ${content}
                </div>`;
                e.clipboardData.setData('text/html', html);

                // Log copy event
                this.logCopyEvent(requestId);

                e.preventDefault();
            }
        });
    }
    
    /**
     * Log copy event to server
     */
    async logCopyEvent(requestId) {
        try {
            await fetch('?api=log-copy', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                },
                body: JSON.stringify({
                    request_id: requestId,
                    timestamp: new Date().toISOString()
                }),
                credentials: 'same-origin'
            });
        } catch (error) {
            console.error('Failed to log copy event:', error);
        }
    }
    
    /**
     * Show toast notification
     */
    showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
    
    /**
     * Copy request ID to clipboard
     */
    copyRequestId(requestId) {
        navigator.clipboard.writeText(requestId).then(() => {
            this.showToast('Request ID kopieret: ' + requestId);
        });
    }
    
    /**
     * Export document (DOCX or PDF)
     */
    exportDocument(requestId, format) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `?api=export-${format}`;
        form.style.display = 'none';

        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf';
        csrf.value = this.csrfToken;
        form.appendChild(csrf);

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'request_id';
        input.value = requestId;
        form.appendChild(input);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    renderMessages(messages) {
        if (!messages || messages.length === 0) {
            this.messageContainer.innerHTML = '<p class="empty-state">Ingen beskeder endnu. Start samtalen!</p>';
            return;
        }
        
        this.messageContainer.innerHTML = messages.map(msg => {
            const role = msg.role;
            const label = role === 'user' ? 'Dig' : role === 'system' ? 'Besked / Kontekst' : 'AI';
            const cssClass = role === 'user' ? 'user' : role === 'system' ? 'system' : 'assistant';
            
            // For AI responses, add special marking
            if (role === 'assistant' && msg.request_id) {
                const requestId = msg.request_id;
                const provider = msg.provider || 'unknown';
                const model = msg.model || 'unknown';
                const timestamp = msg.timestamp || new Date().toISOString();
                const shortId = requestId.substring(0, 8) + '...';

                return `<div class="message ${cssClass} ai-response"
                    data-request-id="${requestId}"
                    data-provider="${provider}"
                    data-model="${model}"
                    data-timestamp="${timestamp}">
                    
                    <div class="ai-badge-container">
                        <div class="ai-badge">
                            <span>🤖</span>
                            <span>AI-genereret</span>
                        </div>
                        <span class="ai-meta">
                            ${provider} · ${model}
                        </span>
                        <span class="ai-timestamp">
                            ${new Date(timestamp).toLocaleDateString('da-DK')} ${new Date(timestamp).toLocaleTimeString('da-DK', {hour: '2-digit', minute: '2-digit'})}
                        </span>
                        <button onclick="app.copyRequestId('${requestId}')" 
                                class="copy-btn" 
                                title="Kopier Request ID">
                            📋 ${shortId}
                        </button>
                    </div>
                    
                    <div class="ai-content message-content">${this.escapeHtml(msg.content)}</div>
                    
                    <div class="ai-footer">
                        <span class="ai-request-id">Request ID: <code>${requestId}</code></span>
                        <span class="ai-policy">Policy: 1.0.0</span>
                        <div class="ai-export-buttons">
                            <button onclick="app.exportDocument('${requestId}', 'docx')" 
                                    class="export-btn docx" 
                                    title="Export til Word">
                                📄 DOCX
                            </button>
                            <button onclick="app.exportDocument('${requestId}', 'pdf')" 
                                    class="export-btn pdf" 
                                    title="Export til PDF">
                                📄 PDF
                            </button>
                        </div>
                    </div>
                </div>`;
            }
            
            return `<div class="message ${cssClass}">
                <div class="message-header"><strong>${label}</strong></div>
                <div class="message-content">${this.escapeHtml(msg.content)}</div>
            </div>`;
        }).join('');
        
        this.messageContainer.scrollTop = this.messageContainer.scrollHeight;
    }
    
    async loadChat(id) {
        try {
            const response = await fetch(`?api=load&id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
            const data = await response.json();
            
            if (data.error) {
                this.showError(data.error);
                return;
            }
            
            this.chatId = id;
            this.renderMessages(data.messages || []);
            this.highlightChat(id);
            
        } catch (error) {
            this.showError('Kunne ikke indlæse chatten');
            console.error('Load chat error:', error);
        }
    }
    
    async refreshChats() {
        try {
            const response = await fetch('?api=list', {credentials:'same-origin', headers: {'Accept': 'application/json'}});
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `API-fejl (${response.status})`);
            }
            
            if (!Array.isArray(data.chats) || data.chats.length === 0) {
                this.chatList.innerHTML = '<div class="empty-chats">Ingen chats endnu</div>';
                return;
            }
            
            this.chatList.innerHTML = data.chats.map(chat => `
                <div class="chat-item ${chat.id === this.chatId ? 'active' : ''}" data-id="${chat.id}">
                    ${this.escapeHtml(chat.title === 'New chat' ? 'Ny chat' : (chat.title || 'Ny chat'))}
                </div>
            `).join('');
            
            this.chatList.querySelectorAll('.chat-item').forEach(item => {
                item.addEventListener('click', () => {
                    this.loadChat(parseInt(item.dataset.id));
                });
            });
            
            this.highlightChat(this.chatId);
            
        } catch (error) {
            this.showError('Kunne ikke indlæse chatlisten');
            console.error('Refresh chats error:', error);
        }
    }
    
    highlightChat(id) {
        this.chatList.querySelectorAll('.chat-item').forEach(item => {
            item.classList.toggle('active', parseInt(item.dataset.id) === id);
        });
    }
    
    async createNewChat() {
        this.setLoading(true);
        try {
            const response = await fetch('?api=new', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': this.csrfToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ csrf: this.csrfToken }),
                credentials: 'same-origin'
            });
            const data = await response.json().catch(() => ({}));
            
            if (!response.ok || !data.id) {
                this.showError(data.error || 'Kunne ikke oprette en ny chat');
                return;
            }

            if (data.id) {
                this.chatId = data.id;
                this.renderMessages([]);
                if (this.botSelect && this.defaultBot) {
                    this.botSelect.value = this.defaultBot;
                }
                await this.refreshChats();
            }
        } catch (error) {
            this.showError('Kunne ikke oprette en ny chat');
            console.error('Create chat error:', error);
        } finally {
            this.setLoading(false);
        }
    }
    
    async handleSubmit(event) {
        event.preventDefault();
        
        if (this.isLoading) return;
        
        const message = this.form.message.value.trim();
        if (!message) return;
        
        const verifyCheckbox = document.getElementById('verifyAnswerCheckbox');
        const payload = {
            message: message,
            bot: this.botSelect.value,
            chat_id: this.chatId,
            github_repo: this.githubRepo ? this.githubRepo.value.trim() : '',
            verify: verifyCheckbox ? verifyCheckbox.checked : false
        };
        
        // Add user message optimistically
        const optimisticMessage = this.appendMessage('user', message);
        this.form.message.value = '';
        
        this.setLoading(true);
        this.clearError();
        
        try {
            const response = await fetch('?api=send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                },
                body: JSON.stringify(payload)
            });
            
            const data = await response.json().catch(() => ({}));
            
            if (!response.ok || data.error) {
                optimisticMessage?.remove();
                this.form.message.value = message;
                this.showError(data.error || 'Anmodningen mislykkedes');
                this.setLoading(false);
                return;
            }
            
            // Add bot response with AI marking metadata
            const requestId = this.generateRequestId();
            this.appendMessage('assistant', data.reply, null, {
                request_id: requestId,
                provider: this.botSelect.selectedOptions[0]?.textContent || 'AI',
                model: this.botSelect.value,
                timestamp: new Date().toISOString()
            });
            await this.refreshChats();
            
        } catch (error) {
            optimisticMessage?.remove();
            this.form.message.value = message;
            this.showError('Serverfejl. Prøv igen.');
            console.error('Send message error:', error);
        } finally {
            this.setLoading(false);
        }
    }
    
    appendMessage(role, content, botName = null, metadata = null) {
        const label = role === 'user' ? 'Dig' : role === 'system' ? 'Besked / Kontekst' : botName || 'AI';
        const cssClass = role === 'user' ? 'user' : role === 'system' ? 'system' : 'assistant';
        
        // If this is an AI response with metadata, use the AI response template
        if (role === 'assistant' && metadata) {
            const requestId = metadata.request_id || this.generateRequestId();
            const provider = metadata.provider || 'unknown';
            const model = metadata.model || 'unknown';
            const timestamp = metadata.timestamp || new Date().toISOString();
            const shortId = requestId.substring(0, 8) + '...';

            const html = `<div class="message ${cssClass} ai-response"
                data-request-id="${requestId}"
                data-provider="${provider}"
                data-model="${model}"
                data-timestamp="${timestamp}">
                
                <div class="ai-badge-container">
                    <div class="ai-badge">
                        <span>🤖</span>
                        <span>AI-genereret</span>
                    </div>
                    <span class="ai-meta">
                        ${this.escapeHtml(provider)} · ${this.escapeHtml(model)}
                    </span>
                    <span class="ai-timestamp">
                        ${new Date(timestamp).toLocaleDateString('da-DK')} ${new Date(timestamp).toLocaleTimeString('da-DK', {hour: '2-digit', minute: '2-digit'})}
                    </span>
                    <button onclick="app.copyRequestId('${requestId}')" 
                            class="copy-btn" 
                            title="Kopier Request ID">
                        📋 ${shortId}
                    </button>
                </div>
                
                <div class="ai-content message-content">${this.escapeHtml(content)}</div>
                
                <div class="ai-footer">
                    <span class="ai-request-id">Request ID: <code>${requestId}</code></span>
                    <span class="ai-policy">Policy: 1.0.0</span>
                    <div class="ai-export-buttons">
                        <button onclick="app.exportDocument('${requestId}', 'docx')" 
                                class="export-btn docx" 
                                title="Export til Word">
                            📄 DOCX
                        </button>
                        <button onclick="app.exportDocument('${requestId}', 'pdf')" 
                                class="export-btn pdf" 
                                title="Export til PDF">
                            📄 PDF
                        </button>
                    </div>
                </div>
            </div>`;
            
            this.messageContainer.insertAdjacentHTML('beforeend', html);
            this.messageContainer.scrollTop = this.messageContainer.scrollHeight;
            return this.messageContainer.lastElementChild;
        }
        
        const html = `<div class="message ${cssClass}">
            <div class="message-header"><strong>${this.escapeHtml(label)}</strong></div>
            <div class="message-content">${this.escapeHtml(content)}</div>
        </div>`;
        
        this.messageContainer.insertAdjacentHTML('beforeend', html);
        this.messageContainer.scrollTop = this.messageContainer.scrollHeight;
        return this.messageContainer.lastElementChild;
    }
    
    setLoading(loading) {
        this.isLoading = loading;
        if (this.form) {
            const btn = this.form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = loading;
        }
        if (this.loader) {
            this.loader.classList.toggle('show', loading);
        }
    }
    
    showError(message) {
        if (!this.errorContainer) return;
        this.errorContainer.textContent = message;
        this.errorContainer.style.display = 'block';
        
        clearTimeout(this.errorTimeout);
        this.errorTimeout = setTimeout(() => {
            this.clearError();
        }, 8000);
    }
    
    clearError() {
        if (!this.errorContainer) return;
        this.errorContainer.textContent = '';
        this.errorContainer.style.display = 'none';
        clearTimeout(this.errorTimeout);
    setupTemplates(templates = null) {
        this.promptTemplates = templates || window.MULTICHAT.promptTemplates || [];
        this.companyToneOfVoice = window.MULTICHAT.companyToneOfVoice || '';
        this.templateModalBtn = document.getElementById('templateModalBtn');
        this.templateModalOverlay = document.getElementById('templateModalOverlay');
        this.closeTemplateModal = document.getElementById('closeTemplateModal');
        this.templateSearch = document.getElementById('templateSearch');
        this.templateListContainer = document.getElementById('templateListContainer');

        this.templateWizardModalOverlay = document.getElementById('templateWizardModalOverlay');
        this.closeWizardModal = document.getElementById('closeWizardModal');
        this.cancelWizardBtn = document.getElementById('cancelWizardBtn');
        this.templateWizardForm = document.getElementById('templateWizardForm');
        this.wizardFieldsContainer = document.getElementById('wizardFieldsContainer');
        this.wizardModalTitle = document.getElementById('wizardModalTitle');
        this.includeToneOfVoiceCheckbox = document.getElementById('includeToneOfVoice');

        if (this.templateModalBtn && this.templateModalOverlay) {
            this.templateModalBtn.addEventListener('click', () => {
                this.openTemplateModal();
            });
        }
        if (this.closeTemplateModal && this.templateModalOverlay) {
            this.closeTemplateModal.addEventListener('click', () => {
                this.closeTemplateModalWindow();
            });
        }
        if (this.templateModalOverlay) {
            this.templateModalOverlay.addEventListener('click', (e) => {
                if (e.target === this.templateModalOverlay) {
                    this.closeTemplateModalWindow();
                }
            });
        }
        if (this.templateSearch) {
            this.templateSearch.addEventListener('input', (e) => {
                this.renderTemplates(e.target.value);
            });
        }

        // Wizard modal listeners
        if (this.closeWizardModal && this.templateWizardModalOverlay) {
            this.closeWizardModal.addEventListener('click', () => {
                this.closeWizardModalWindow();
            });
        }
        if (this.cancelWizardBtn && this.templateWizardModalOverlay) {
            this.cancelWizardBtn.addEventListener('click', () => {
                this.closeWizardModalWindow();
            });
        }
        if (this.templateWizardModalOverlay) {
            this.templateWizardModalOverlay.addEventListener('click', (e) => {
                if (e.target === this.templateWizardModalOverlay) {
                    this.closeWizardModalWindow();
                }
            });
        }
        if (this.templateWizardForm) {
            this.templateWizardForm.addEventListener('submit', (e) => {
                this.handleWizardSubmit(e);
            });
        }
    }

    openTemplateModal() {
        if (this.templateModalOverlay) {
            this.templateModalOverlay.classList.add('active');
            if (this.templateSearch) {
                this.templateSearch.value = '';
                this.templateSearch.focus();
            }
            this.renderTemplates('');
        }
    }

    closeTemplateModalWindow() {
        if (this.templateModalOverlay) {
            this.templateModalOverlay.classList.remove('active');
        }
    }

    closeWizardModalWindow() {
        if (this.templateWizardModalOverlay) {
            this.templateWizardModalOverlay.classList.remove('active');
        }
    }

    renderTemplates(query = '') {
        if (!this.templateListContainer) return;
        
        const q = query.toLowerCase().trim();
        const filtered = this.promptTemplates.filter(t => 
            t.title.toLowerCase().includes(q) || 
            t.category.toLowerCase().includes(q) || 
            t.prompt_text.toLowerCase().includes(q)
        );

        if (filtered.length === 0) {
            this.templateListContainer.innerHTML = '<p style="text-align:center; color: var(--text-muted); padding: 20px;">Ingen skabelon-prompts fundet.</p>';
            return;
        }

        // Group by category
        const groups = {};
        filtered.forEach(t => {
            const cat = t.category || 'Generel';
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(t);
        });

        let html = '';
        for (const [category, templates] of Object.entries(groups)) {
            html += `<div class="template-category-group">
                <div class="template-category-title">${this.escapeHtml(category)}</div>`;
            
            templates.forEach(t => {
                html += `<div class="template-card">
                    <div class="template-card-title">${this.escapeHtml(t.title)}</div>
                    <div class="template-card-text">${this.escapeHtml(t.prompt_text)}</div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                        <span style="font-size: 11px; color: var(--text-muted);">📊 ${t.usage_count} anvendelser</span>
                        <button type="button" class="template-use-btn" data-id="${t.id}">
                            <span>Brug skabelon</span>
                            <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                    </div>
                </div>`;
            });
            html += `</div>`;
        }

        this.templateListContainer.innerHTML = html;

        // Attach click listeners to use buttons
        this.templateListContainer.querySelectorAll('.template-use-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = parseInt(btn.getAttribute('data-id'), 10);
                const template = this.promptTemplates.find(tp => tp.id === id);
                if (template) {
                    this.closeTemplateModalWindow();
                    this.openWizardModal(template);
                }
            });
        });
    }

    openWizardModal(template) {
        this.currentActiveTemplate = template;
        if (this.wizardModalTitle) {
            this.wizardModalTitle.textContent = '✨ Udfyld felter: ' + template.title;
        }

        // Scan prompt text for placeholders like {felt_navn} or [Felt Navn]
        const regex = /\{([^}]+)\}|\[([^\]]+)\]/g;
        let match;
        const placeholders = new Set();
        while ((match = regex.exec(template.prompt_text)) !== null) {
            const p = match[1] || match[2];
            if (p) placeholders.add(p.trim());
        }

        if (this.wizardFieldsContainer) {
            if (placeholders.size === 0) {
                this.wizardFieldsContainer.innerHTML = '<p style="font-size:13px; color:var(--text-muted);">Denne skabelon har ingen dynamiske felter. Du kan indsætte den direkte.</p>';
            } else {
                let fieldsHtml = '';
                placeholders.forEach(ph => {
                    const fieldId = 'ph_' + ph.replace(/[^a-zA-Z0-9]/g, '_');
                    fieldsHtml += `<div>
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">${this.escapeHtml(ph)}</label>
                        <input type="text" name="${this.escapeHtml(ph)}" id="${fieldId}" placeholder="Indtast ${this.escapeHtml(ph)}..." required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px;">
                    </div>`;
                });
                this.wizardFieldsContainer.innerHTML = fieldsHtml;
            }
        }

        if (this.templateWizardModalOverlay) {
            this.templateWizardModalOverlay.classList.add('active');
        }
    }

    async handleWizardSubmit(e) {
        e.preventDefault();
        if (!this.currentActiveTemplate) return;

        let processedText = this.currentActiveTemplate.prompt_text;
        const formData = new FormData(this.templateWizardForm);

        // Replace placeholders
        formData.forEach((value, key) => {
            const val = value.toString().trim();
            processedText = processedText.replace(new RegExp('\{' + key + '\}', 'g'), val);
            processedText = processedText.replace(new RegExp('\[' + key + '\]', 'g'), val);
        });

        // Append Tone of Voice if checked
        if (this.includeToneOfVoiceCheckbox && this.includeToneOfVoiceCheckbox.checked && this.companyToneOfVoice) {
            processedText = `[Virksomhedens Tone of Voice retningslinjer: ${this.companyToneOfVoice}]

` + processedText;
        }

        // Insert into chat textarea
        const textarea = document.querySelector('textarea[name="message"]');
        if (textarea) {
            textarea.value = processedText;
            textarea.focus();
            textarea.style.height = 'auto';
            textarea.style.height = (textarea.scrollHeight) + 'px';
        }

        this.closeWizardModalWindow();
        this.showToast('Skabelon indsat i chat!');

        // Increment usage count via API
        try {
            await fetch('?api=use_template', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                },
                body: JSON.stringify({ template_id: this.currentActiveTemplate.id }),
                credentials: 'same-origin'
            });
            this.currentActiveTemplate.usage_count = (this.currentActiveTemplate.usage_count || 0) + 1;
        } catch (err) {
            console.error('Failed to increment template usage:', err);
        }
    }

    setupFileUpload() {
        this.uploadFileBtn = document.getElementById('uploadFileBtn');
        this.chatFileInput = document.getElementById('chatFileInput');
        this.attachedFilesContainer = document.getElementById('attachedFilesContainer');
        this.attachedFiles = [];

        if (this.uploadFileBtn && this.chatFileInput) {
            this.uploadFileBtn.addEventListener('click', () => {
                this.chatFileInput.click();
            });

            this.chatFileInput.addEventListener('change', (e) => {
                if (e.target.files && e.target.files[0]) {
                    this.uploadFile(e.target.files[0]);
                }
            });
        }

        const chatForm = document.querySelector('form');
        if (chatForm) {
            ['dragenter', 'dragover'].forEach(eventName => {
                chatForm.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    chatForm.style.borderColor = 'var(--primary-color)';
                }, false);
            });
            ['dragleave', 'drop'].forEach(eventName => {
                chatForm.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    chatForm.style.borderColor = '';
                }, false);
            });
            chatForm.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files && files[0]) {
                    this.uploadFile(files[0]);
                }
            }, false);
        }
    }

    async uploadFile(file) {
        const formData = new FormData();
        formData.append('file', file);

        this.showToast('Uploader og analyserer dokument...');
        try {
            const res = await fetch('?api=upload_file', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': this.csrfToken
                },
                body: formData,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.success) {
                this.attachedFiles.push(data);
                this.renderAttachedFiles();
                this.showToast('Dokument vedhæftet!');

                const textarea = document.querySelector('textarea[name="message"]');
                if (textarea) {
                    const fileTextHeader = `\n\n[Vedhæftet dokument: ${data.filename}]\n${data.extracted_text}\n`;
                    textarea.value += fileTextHeader;
                    textarea.style.height = 'auto';
                    textarea.style.height = (textarea.scrollHeight) + 'px';
                }
            } else {
                this.showToast(data.error || 'Fejl ved upload af fil.');
            }
        } catch (err) {
            console.error('Upload error:', err);
            this.showToast('Kunne ikke uploade fil.');
        }
    }

    renderAttachedFiles() {
        if (!this.attachedFilesContainer) return;
        if (this.attachedFiles.length === 0) {
            this.attachedFilesContainer.style.display = 'none';
            this.attachedFilesContainer.innerHTML = '';
            return;
        }

        this.attachedFilesContainer.style.display = 'flex';
        let html = '';
        this.attachedFiles.forEach((f, idx) => {
            html += `<div style="background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px; padding: 4px 10px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px;">
                <span>📎 <strong>${this.escapeHtml(f.filename)}</strong></span>
                <button type="button" class="remove-file-btn" data-idx="${idx}" style="background:none; border:none; cursor:pointer; color:#ef4444; font-weight:bold;">&times;</button>
            </div>`;
        });
        this.attachedFilesContainer.innerHTML = html;

        this.attachedFilesContainer.querySelectorAll('.remove-file-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = parseInt(btn.getAttribute('data-idx'), 10);
                this.attachedFiles.splice(idx, 1);
                this.renderAttachedFiles();
            });
        });
    }

    setupSmartPaste() {
        const textarea = document.querySelector('textarea[name="message"]');
        const pasteNotification = document.getElementById('pasteNotification');
        const pasteNotificationText = document.getElementById('pasteNotificationText');
        const closePasteNotif = document.getElementById('closePasteNotif');

        if (!textarea) return;

        textarea.addEventListener('paste', (e) => {
            const clipboardData = e.clipboardData || window.clipboardData;
            const pastedText = clipboardData.getData('text');

            if (pastedText && pastedText.length > 800) {
                if (pasteNotification && pasteNotificationText) {
                    pasteNotificationText.textContent = `📄 Lang tekst indsat og renset (${pastedText.length.toLocaleString()} tegn). Klar til AI-analyse.`;
                    pasteNotification.style.display = 'flex';
                }
            }
        });

        if (closePasteNotif && pasteNotification) {
            closePasteNotif.addEventListener('click', () => {
                pasteNotification.style.display = 'none';
            });
        }
    }

}

let app;

window.addEventListener('DOMContentLoaded', () => {
    app = new MultiChatApp(window.MULTICHAT || {});
    app.init().catch((error) => {
        console.error('Multi-Chat initialization error:', error);
        if (app && app.errorContainer) {
            app.showError('Chatten kunne ikke startes. Genindlæs siden.');
        }
    });
    if (app && typeof app.setupTemplates === 'function') {
        app.setupTemplates();
    app.setupFileUpload();
    app.setupSmartPaste();
    }
});
