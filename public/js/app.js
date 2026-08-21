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
        
        const payload = {
            message: message,
            bot: this.botSelect.value,
            chat_id: this.chatId,
            github_repo: this.githubRepo ? this.githubRepo.value.trim() : ''
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
    }
}

// Global app instance
let app;

window.addEventListener('DOMContentLoaded', () => {
    app = new MultiChatApp(window.MULTICHAT || {});
    app.init().catch((error) => {
        console.error('Multi-Chat initialization error:', error);
        if (app && app.errorContainer) {
            app.showError('Chatten kunne ikke startes. Genindlæs siden.');
        }
    });
});


    setupTemplates(templates = null) {
        this.promptTemplates = templates || window.MULTICHAT.promptTemplates || [];
        this.templateModalBtn = document.getElementById('templateModalBtn');
        this.templateModalOverlay = document.getElementById('templateModalOverlay');
        this.closeTemplateModal = document.getElementById('closeTemplateModal');
        this.templateSearch = document.getElementById('templateSearch');
        this.templateListContainer = document.getElementById('templateListContainer');

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
                    <button type="button" class="template-use-btn" data-id="${t.id}">
                        <span>Brug skabelon</span>
                        <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </button>
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
                    const textarea = document.querySelector('textarea[name="message"]');
                    if (textarea) {
                        textarea.value = template.prompt_text;
                        textarea.focus();
                        textarea.style.height = 'auto';
                        textarea.style.height = (textarea.scrollHeight) + 'px';
                    }
                    this.closeTemplateModalWindow();
                }
            });
        });
    }
