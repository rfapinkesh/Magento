/**
 * IceCube AI Agent: admin chat UI.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    var ESCAPES = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

    function escapeHtml(text) {
        return String(text === null || text === undefined ? '' : text).replace(/[&<>"']/g, function (ch) {
            return ESCAPES[ch];
        });
    }

    /**
     * Small, safe markdown renderer: everything is HTML-escaped first, then a known
     * subset (tables, lists, headings, bold, italic, inline code, code blocks and
     * links back into this admin) is turned into markup.
     */
    function Markdown(adminOrigin) {
        this.adminOrigin = adminOrigin || window.location.origin;
    }

    Markdown.prototype.isSafeUrl = function (url) {
        var decoded = url.replace(/&amp;/g, '&');

        if (decoded.indexOf(this.adminOrigin + '/') === 0) {
            return true;
        }

        return decoded.charAt(0) === '/' && decoded.charAt(1) !== '/';
    };

    Markdown.prototype.inline = function (text) {
        var self = this,
            codes = [],
            out = escapeHtml(text);

        out = out.replace(/`([^`]+)`/g, function (match, code) {
            codes.push('<code>' + code + '</code>');

            return '\u0000' + (codes.length - 1) + '\u0000';
        });
        out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (match, label, url) {
            if (self.isSafeUrl(url)) {
                return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            }

            return label;
        });
        out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        out = out.replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?![*\w])/g, '$1<em>$2</em>');
        out = out.replace(/\u0000(\d+)\u0000/g, function (match, index) {
            return codes[Number(index)];
        });

        return out;
    };

    Markdown.prototype.splitRow = function (line) {
        var row = line.trim();

        if (row.charAt(0) === '|') {
            row = row.slice(1);
        }
        if (row.charAt(row.length - 1) === '|') {
            row = row.slice(0, -1);
        }

        return row.split('|').map(function (cell) {
            return cell.trim();
        });
    };

    Markdown.prototype.isTableSeparator = function (line) {
        return /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(line || '');
    };

    Markdown.prototype.isBlockStart = function (line, next) {
        return /^```/.test(line) ||
            /^#{1,4}\s+/.test(line) ||
            /^\s*[-*+]\s+/.test(line) ||
            /^\s*\d+[.)]\s+/.test(line) ||
            /^\s*(-{3,}|\*{3,})\s*$/.test(line) ||
            (line.trim().charAt(0) === '|' && this.isTableSeparator(next));
    };

    Markdown.prototype.render = function (text) {
        var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n'),
            html = [],
            i = 0,
            line,
            buffer,
            match,
            level,
            self = this;

        while (i < lines.length) {
            line = lines[i];

            if (/^```/.test(line)) {
                buffer = [];
                i++;
                while (i < lines.length && !/^```/.test(lines[i])) {
                    buffer.push(lines[i]);
                    i++;
                }
                i++;
                html.push('<pre><code>' + escapeHtml(buffer.join('\n')) + '</code></pre>');
                continue;
            }

            if (line.trim().charAt(0) === '|' && this.isTableSeparator(lines[i + 1])) {
                buffer = ['<div class="icecube-aiagent__table">' +
                    '<button type="button" class="icecube-aiagent__csv" data-role="export-csv">' +
                    escapeHtml($t('Download CSV')) + '</button><table><thead><tr>'];
                this.splitRow(line).forEach(function (cell) {
                    buffer.push('<th>' + self.inline(cell) + '</th>');
                });
                buffer.push('</tr></thead><tbody>');
                i += 2;
                while (i < lines.length && lines[i].trim().charAt(0) === '|') {
                    buffer.push('<tr>');
                    this.splitRow(lines[i]).forEach(function (cell) {
                        buffer.push('<td>' + self.inline(cell) + '</td>');
                    });
                    buffer.push('</tr>');
                    i++;
                }
                buffer.push('</tbody></table></div>');
                html.push(buffer.join(''));
                continue;
            }

            match = line.match(/^(#{1,4})\s+(.*)$/);
            if (match) {
                level = Math.min(5, match[1].length + 2);
                html.push('<h' + level + '>' + this.inline(match[2]) + '</h' + level + '>');
                i++;
                continue;
            }

            if (/^\s*(-{3,}|\*{3,})\s*$/.test(line)) {
                html.push('<hr>');
                i++;
                continue;
            }

            if (/^\s*[-*+]\s+/.test(line)) {
                buffer = [];
                while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) {
                    buffer.push('<li>' + this.inline(lines[i].replace(/^\s*[-*+]\s+/, '')) + '</li>');
                    i++;
                }
                html.push('<ul>' + buffer.join('') + '</ul>');
                continue;
            }

            if (/^\s*\d+[.)]\s+/.test(line)) {
                buffer = [];
                while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) {
                    buffer.push('<li>' + this.inline(lines[i].replace(/^\s*\d+[.)]\s+/, '')) + '</li>');
                    i++;
                }
                html.push('<ol>' + buffer.join('') + '</ol>');
                continue;
            }

            if (line.trim() === '') {
                i++;
                continue;
            }

            buffer = [];
            while (i < lines.length && lines[i].trim() !== '' && !this.isBlockStart(lines[i], lines[i + 1])) {
                buffer.push(this.inline(lines[i]));
                i++;
            }
            html.push('<p>' + buffer.join('<br>') + '</p>');
        }

        return html.join('');
    };

    function init(config, element) {
        var $root = $(element),
            $messages = $root.find('[data-role="messages"]'),
            $welcome = $root.find('[data-role="welcome"]'),
            $history = $root.find('[data-role="history"]'),
            $form = $root.find('[data-role="composer"]'),
            $input = $root.find('[data-role="input"]'),
            $send = $root.find('[data-role="send"]'),
            markdown = new Markdown(config.adminOrigin),
            state = {
                conversationId: null,
                busy: false
            };

        function scrollToBottom() {
            $messages.scrollTop($messages[0].scrollHeight);
        }

        function setBusy(busy) {
            state.busy = busy;
            $send.prop('disabled', busy || !config.ready);
            $input.prop('disabled', !config.ready);
            $root.toggleClass('is-busy', busy);
        }

        function toolChips(tools) {
            if (!tools || !tools.length) {
                return '';
            }

            return '<div class="icecube-aiagent__used">' + escapeHtml($t('Looked up:')) + ' ' +
                tools.map(function (name) {
                    return '<span class="icecube-aiagent__chip">' + escapeHtml(name) + '</span>';
                }).join(' ') + '</div>';
        }

        function addMessage(role, content, tools) {
            var body = role === 'assistant' ? markdown.render(content) : escapeHtml(content).replace(/\n/g, '<br>'),
                $msg = $('<div/>', {'class': 'icecube-aiagent__msg icecube-aiagent__msg--' + role});

            $welcome.hide();
            $msg.html('<div class="icecube-aiagent__bubble">' + body + '</div>' +
                (role === 'assistant' ? toolChips(tools) : ''));
            $messages.append($msg);
            scrollToBottom();

            return $msg;
        }

        /**
         * Downloads one answer table as CSV. Cells starting like a formula get a leading quote
         * so spreadsheet apps show them as text.
         */
        function exportTable(table) {
            var csv = [],
                stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, ''),
                blob,
                link;

            $(table).find('tr').each(function () {
                var cells = [];

                $(this).children('th,td').each(function () {
                    var value = $(this).text().trim();

                    if (/^[=+\-@\t\r]/.test(value)) {
                        value = '\'' + value;
                    }
                    cells.push('"' + value.replace(/"/g, '""') + '"');
                });
                csv.push(cells.join(','));
            });

            // The BOM makes Excel read the file as UTF-8.
            blob = new Blob(['﻿' + csv.join('\r\n')], {type: 'text/csv;charset=utf-8'});
            link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'ai-assistant-' + stamp + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () {
                URL.revokeObjectURL(link.href);
            }, 1000);
        }

        $messages.on('click', '[data-role="export-csv"]', function () {
            exportTable($(this).closest('.icecube-aiagent__table').find('table')[0]);
        });

        function addError(text) {
            var $msg = $('<div/>', {'class': 'icecube-aiagent__msg icecube-aiagent__msg--error'});

            $msg.html('<div class="icecube-aiagent__bubble">' + escapeHtml(text) + '</div>');
            $messages.append($msg);
            scrollToBottom();
        }

        function showTyping() {
            var $typing = $('<div class="icecube-aiagent__msg icecube-aiagent__msg--assistant" data-role="typing">' +
                '<div class="icecube-aiagent__bubble icecube-aiagent__typing">' +
                '<span></span><span></span><span></span> ' + escapeHtml($t('Looking that up')) + '</div></div>');

            $messages.append($typing);
            scrollToBottom();

            return $typing;
        }

        function clearMessages() {
            $messages.children().not($welcome).remove();
            $welcome.show();
        }

        function renderHistory(conversations) {
            $history.empty();
            if (!conversations.length) {
                $history.append($('<li class="icecube-aiagent__history-empty"/>').text($t('No chats yet.')));

                return;
            }
            conversations.forEach(function (item) {
                var $li = $('<li/>', {'class': 'icecube-aiagent__history-item'}),
                    $open = $('<button type="button" class="icecube-aiagent__history-open"/>'),
                    $delete = $('<button type="button" class="icecube-aiagent__history-delete"/>');

                $open.append($('<span class="icecube-aiagent__history-name"/>').text(item.title || $t('Untitled chat')));
                $open.append($('<span class="icecube-aiagent__history-date"/>').text(item.updated_at || ''));
                $open.on('click', function () {
                    loadConversation(item.id);
                });
                $delete.attr({'title': $t('Delete chat'), 'aria-label': $t('Delete chat')}).text('×');
                $delete.on('click', function (event) {
                    event.stopPropagation();
                    deleteConversation(item.id);
                });
                $li.toggleClass('is-active', item.id === state.conversationId);
                $li.attr('data-id', item.id).append($open, $delete);
                $history.append($li);
            });
        }

        function markActive() {
            $history.children().each(function () {
                $(this).toggleClass('is-active', Number($(this).attr('data-id')) === state.conversationId);
            });
        }

        function handleAjaxFailure(xhr) {
            if (xhr && xhr.status === 401) {
                addError($t('Your admin session has expired. Please reload the page.'));
            } else {
                addError($t('The request failed. Check your connection or reload the page and try again.'));
            }
        }

        function loadHistory() {
            $.ajax({
                url: config.historyUrl,
                type: 'GET',
                dataType: 'json',
                data: {isAjax: true}
            }).done(function (response) {
                if (response && response.success) {
                    renderHistory(response.conversations || []);
                }
            });
        }

        function loadConversation(id) {
            if (state.busy) {
                return;
            }
            setBusy(true);
            $.ajax({
                url: config.historyUrl,
                type: 'GET',
                dataType: 'json',
                data: {isAjax: true, conversation_id: id}
            }).done(function (response) {
                clearMessages();
                if (!response || !response.success) {
                    addError((response && response.error) || $t('Could not open this chat.'));

                    return;
                }
                state.conversationId = response.conversation.id;
                (response.messages || []).forEach(function (msg) {
                    addMessage(msg.role, msg.content, msg.tools_used);
                });
                markActive();
            }).fail(handleAjaxFailure).always(function () {
                setBusy(false);
                $input.trigger('focus');
            });
        }

        function deleteConversation(id) {
            if (!window.confirm($t('Delete this chat? This cannot be undone.'))) {
                return;
            }
            $.ajax({
                url: config.deleteUrl,
                type: 'POST',
                dataType: 'json',
                data: {isAjax: true, form_key: window.FORM_KEY, conversation_id: id}
            }).done(function () {
                if (state.conversationId === id) {
                    newChat();
                }
                loadHistory();
            }).fail(handleAjaxFailure);
        }

        function newChat() {
            state.conversationId = null;
            clearMessages();
            markActive();
            $input.val('').trigger('input').trigger('focus');
        }

        function send(text) {
            var message = String(text || '').trim(),
                $typing;

            if (!message || state.busy || !config.ready) {
                return;
            }
            if (message.length > config.maxLength) {
                addError($t('Your message is too long. Please shorten it.'));

                return;
            }

            addMessage('user', message);
            $input.val('').trigger('input');
            $typing = showTyping();
            setBusy(true);

            $.ajax({
                url: config.sendUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 300000,
                data: {
                    isAjax: true,
                    form_key: window.FORM_KEY,
                    message: message,
                    conversation_id: state.conversationId || ''
                }
            }).done(function (response) {
                $typing.remove();
                if (response && response.ajaxExpired) {
                    addError($t('Your admin session has expired. Please reload the page.'));

                    return;
                }
                if (!response || !response.success) {
                    addError((response && response.error) || $t('No answer came back. Please try again.'));

                    return;
                }
                state.conversationId = response.conversation_id;
                addMessage('assistant', response.answer, response.tools_used);
                loadHistory();
            }).fail(function (xhr) {
                $typing.remove();
                handleAjaxFailure(xhr);
            }).always(function () {
                setBusy(false);
                $input.trigger('focus');
            });
        }

        // Examples and available tools on the welcome screen.
        (config.examples || []).forEach(function (example) {
            $('<button type="button" class="icecube-aiagent__example"/>')
                .text(example)
                .on('click', function () {
                    send(example);
                })
                .appendTo($root.find('[data-role="examples"]'));
        });
        if (config.tools && config.tools.length) {
            $root.find('[data-role="tools"]').html(
                escapeHtml($t('Lookups available to you:')) + ' ' +
                config.tools.map(function (tool) {
                    return '<span class="icecube-aiagent__chip">' + escapeHtml(tool.label) + '</span>';
                }).join(' ')
            );
        }

        $form.on('submit', function (event) {
            event.preventDefault();
            send($input.val());
        });

        $input.on('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                send($input.val());
            }
        });

        $input.on('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 200) + 'px';
        });

        $root.find('[data-role="new-chat"]').on('click', newChat);

        setBusy(false);
        loadHistory();
        if (config.ready) {
            $input.trigger('focus');
        }
    }

    // Exposed for testing.
    init.Markdown = Markdown;

    return init;
});
