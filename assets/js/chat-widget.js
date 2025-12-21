(function($) {
    'use strict';
    
    class AILawBot {
        constructor() {
            this.config = window.aiLawBotConfig || {};
            this.isOpen = false;
            this.isProcessing = false;
            this.sessionId = this.getOrCreateSessionId();
            this.conversationHistory = [];
            
            this.init();
        }
        
        init() {
            this.createWidget();
            this.attachEventListeners();
        }
        
        getOrCreateSessionId() {
            let sid = sessionStorage.getItem('ai_law_bot_session');
            if (!sid) {
                sid = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                sessionStorage.setItem('ai_law_bot_session', sid);
            }
            return sid;
        }
        
        createWidget() {
            var s = this.config.strings || {};
            var html = '<div id="ai-law-bot-widget" class="ai-law-bot-closed">' +
                '<div class="ai-law-bot-button" title="' + (s.chatTitle || 'مساعد قانوني') + '">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="white"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>' +
                '</div>' +
                '<div class="ai-law-bot-window">' +
                '<div class="ai-law-bot-header"><h3>' + (s.chatTitle || 'مساعد قانوني ذكي') + '</h3>' +
                '<div class="ai-law-bot-controls">' +
                '<button class="ai-law-bot-close" title="إغلاق"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>' +
                '</div></div>' +
                '<div class="ai-law-bot-messages">' +
                '<div class="ai-law-bot-message ai-law-bot-message-bot"><div class="ai-law-bot-message-content">' +
                '<p>مرحبًا بك 👋</p><p>أنا المساعد القانوني الذكي.</p><p>اكتب سؤالك وسأساعدك.</p>' +
                '</div></div></div>' +
                '<div class="ai-law-bot-input-area">' +
                '<textarea class="ai-law-bot-input" placeholder="' + (s.placeholder || 'اكتب سؤالك...') + '" rows="1"></textarea>' +
                '<button class="ai-law-bot-send" disabled><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="white"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>' +
                '</div></div></div>';
            
            $('#ai-law-bot-container').html(html);
        }
        
        attachEventListeners() {
            var self = this;
            var $w = $('#ai-law-bot-widget');
            
            $w.find('.ai-law-bot-button').on('click', function() { self.toggleChat(); });
            $w.find('.ai-law-bot-close').on('click', function() { self.closeChat(); });
            
            $w.find('.ai-law-bot-input').on('input', function() {
                $w.find('.ai-law-bot-send').prop('disabled', !this.value.trim());
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
            
            $w.find('.ai-law-bot-input').on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });
            
            $w.find('.ai-law-bot-send').on('click', function() { self.sendMessage(); });
        }
        
        toggleChat() {
            this.isOpen ? this.closeChat() : this.openChat();
        }
        
        openChat() {
            $('#ai-law-bot-widget').removeClass('ai-law-bot-closed').addClass('ai-law-bot-open');
            this.isOpen = true;
            setTimeout(function() { $('#ai-law-bot-widget .ai-law-bot-input').focus(); }, 300);
        }
        
        closeChat() {
            $('#ai-law-bot-widget').removeClass('ai-law-bot-open').addClass('ai-law-bot-closed');
            this.isOpen = false;
        }
        
        async sendMessage() {
            var $input = $('#ai-law-bot-widget .ai-law-bot-input');
            var question = $input.val().trim();
            
            if (!question || this.isProcessing) return;
            
            this.addMessage(question, 'user');
            $input.val('').css('height', 'auto');
            $('#ai-law-bot-widget .ai-law-bot-send').prop('disabled', true);
            
            this.showThinking();
            this.isProcessing = true;
            
            try {
                var response = await this.callAPI(question);
                this.removeThinking();
                this.addBotResponse(response);
            } catch (error) {
                this.removeThinking();
                this.addMessage(error.message || 'حدث خطأ', 'error');
            }
            
            this.isProcessing = false;
        }
        
        async callAPI(question) {
            var headers = { 'Content-Type': 'application/json' };
            if (this.config.nonce) headers['X-WP-Nonce'] = this.config.nonce;
            
            var response = await fetch(this.config.apiUrl, {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                body: JSON.stringify({ question: question, session_id: this.sessionId })
            });
            
            var data = await response.json();
            if (!response.ok) throw new Error(data.message || 'خطأ');
            return data;
        }
        
        addMessage(text, type) {
            var cls = type === 'user' ? 'ai-law-bot-message-user' : (type === 'error' ? 'ai-law-bot-message-error' : 'ai-law-bot-message-bot');
            var html = '<div class="ai-law-bot-message ' + cls + '"><div class="ai-law-bot-message-content"><p>' + this.escapeHtml(text) + '</p></div></div>';
            $('#ai-law-bot-widget .ai-law-bot-messages').append(html);
            this.scrollToBottom();
        }
        
        addBotResponse(data) {
            var msg = (data.message || '').replace(/\n/g, '<br>');
            var html = '<div class="ai-law-bot-message ai-law-bot-message-bot"><div class="ai-law-bot-message-content">' + msg;
            if (data.remaining !== 'unlimited' && data.remaining !== undefined) {
                html += '<p class="ai-law-bot-remaining"><small>الأسئلة المتبقية: ' + data.remaining + '</small></p>';
            }
            html += '</div></div>';
            $('#ai-law-bot-widget .ai-law-bot-messages').append(html);
            this.scrollToBottom();
        }
        
        showThinking() {
            var html = '<div class="ai-law-bot-message ai-law-bot-message-bot ai-law-bot-thinking"><div class="ai-law-bot-message-content"><div class="ai-law-bot-dots"><span></span><span></span><span></span></div></div></div>';
            $('#ai-law-bot-widget .ai-law-bot-messages').append(html);
            this.scrollToBottom();
        }
        
        removeThinking() {
            $('#ai-law-bot-widget .ai-law-bot-thinking').remove();
        }
        
        scrollToBottom() {
            var $m = $('#ai-law-bot-widget .ai-law-bot-messages');
            $m.scrollTop($m[0].scrollHeight);
        }
        
        escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }
    
    $(document).ready(function() {
        if ($('#ai-law-bot-container').length) {
            new AILawBot();
        }
    });
    
})(jQuery);
