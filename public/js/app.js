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
    }
    
    async init() {
        await this.refreshChats();
        if (this.chatId > 0) {
            await this.loadChat(this.chatId);
        }
        
        this.setupEventListeners();
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
    
    renderMessages(messages) {
        if (!messages || messages.length === 0) {
            this.messageContainer.innerHTML = '<p class="empty-state">Ingen beskeder endnu. Start samtalen!</p>';
            return;
        }
        
        this.messageContainer.innerHTML = messages.map(msg => {
            const role = msg.role;
            const label = role === 'user' ? 'Dig' : role === 'system' ? 'Besked / Kontekst' : 'AI';
            const cssClass = role === 'user' ? 'user' : role === 'system' ? 'system' : 'assistant';
            
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
                    <span class="chat-title">${this.escapeHtml(chat.title === 'New chat' ? 'Ny chat' : (chat.title || 'Ny chat'))}</span>
                    <button class="delete-chat-btn" type="button" data-delete-id="${chat.id}" aria-label="Slet samtale">×</button>
                </div>
            `).join('');
            
            this.chatList.querySelectorAll('.chat-item').forEach(item => {
                item.addEventListener('click', () => {
                    this.loadChat(parseInt(item.dataset.id));
                });
            });
            this.chatList.querySelectorAll('.delete-chat-btn').forEach(button => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    this.deleteChat(parseInt(button.dataset.deleteId));
                });
            });
            
            this.highlightChat(this.chatId);
            
        } catch (error) {
            this.showError('Kunne ikke indlæse chatlisten');
            console.error('Refresh chats error:', error);
        }
    }

    async deleteChat(id) {
        if (!Number.isInteger(id) || id <= 0 || !window.confirm('Vil du slette denne samtale permanent?')) {
            return;
        }
        this.setLoading(true);
        try {
            const response = await fetch('?api=delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                },
                body: JSON.stringify({chat_id: id}),
                credentials: 'same-origin'
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.deleted) {
                this.showError(data.error || 'Samtalen kunne ikke slettes');
                return;
            }

            if (this.chatId === id) {
                this.chatId = 0;
                this.renderMessages([]);
            }
            await this.refreshChats();
            const firstChat = this.chatList.querySelector('.chat-item');
            if (this.chatId === 0 && firstChat) {
                await this.loadChat(parseInt(firstChat.dataset.id));
            } else if (!firstChat) {
                await this.createNewChat();
            }
        } catch (error) {
            this.showError('Samtalen kunne ikke slettes');
            console.error('Delete chat error:', error);
        } finally {
            this.setLoading(false);
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
            
            // Add bot response
            this.appendMessage('assistant', data.reply);
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
    
    appendMessage(role, content, botName = null) {
        const label = role === 'user' ? 'Dig' : role === 'system' ? 'Besked / Kontekst' : botName || 'AI';
        const cssClass = role === 'user' ? 'user' : role === 'system' ? 'system' : 'assistant';
        
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

window.addEventListener('DOMContentLoaded', () => {
    const app = new MultiChatApp(window.MULTICHAT || {});
    app.init().catch((error) => {
        console.error('Multi-Chat initialization error:', error);
        app.showError('Chatten kunne ikke startes. Genindlæs siden.');
    });
});
