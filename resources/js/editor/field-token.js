import { Extension, Node, mergeAttributes } from '@tiptap/core';
import Suggestion from '@tiptap/suggestion';

/**
 * Smart chips — §3.1/§3.2 of the master spec. An inline atom, so a chip is
 * inserted and deleted as one unit rather than edited letter-by-letter; the
 * server resolves what it displays every time the body is rendered
 * (DocumentComposer::resolveFieldChips), so a chip typed once keeps showing
 * the record's *current* value rather than freezing it at insertion time.
 *
 * The HTML shape (`<span data-token="…">label</span>`) is exactly what
 * HtmlSanitizer::ALLOWED_ATTRIBUTES['span'] keeps — anything else on the
 * span is stripped server-side regardless of what this extension produces.
 */
export const FieldToken = Node.create({
    name: 'fieldToken',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addAttributes() {
        return {
            token: { default: null, parseHTML: (el) => el.getAttribute('data-token') },
            label: { default: '', parseHTML: (el) => el.textContent },
        };
    },

    parseHTML() {
        return [{ tag: 'span[data-token]' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'span',
            mergeAttributes(HTMLAttributes, { 'data-token': node.attrs.token }),
            node.attrs.label,
        ];
    },
});

/**
 * The `@` trigger: typing `@` opens a lookahead list of every field the
 * current document can resolve (customer.name, employee.name, …, as
 * DocumentComposer::availableTokens() reports them), and choosing one
 * inserts a FieldToken chip in place of the typed text.
 *
 * `items` is a plain {token: label} object handed in from the Livewire
 * component's availableTokens prop — no network round-trip on every
 * keystroke, since the field list for a given document does not change
 * while the editor is open.
 */
function fieldTokenSuggestionOptions(fields) {
    const entries = Object.entries(fields || {});

    return {
        char: '@',
        allowSpaces: false,

        items: ({ query }) => {
            const q = query.toLowerCase();

            return entries
                .filter(([token, label]) => token.toLowerCase().includes(q) || label.toLowerCase().includes(q))
                .slice(0, 8);
        },

        command: ({ editor, range, props: [token, label] }) => {
            editor.chain().focus().insertContentAt(range, [
                { type: 'fieldToken', attrs: { token, label } },
                { type: 'text', text: ' ' },
            ]).run();
        },

        render: () => {
            let popup;

            const build = (items, command) => {
                popup.innerHTML = '';

                if (items.length === 0) {
                    popup.classList.add('hidden');

                    return;
                }

                popup.classList.remove('hidden');

                items.forEach(([token, label]) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'block w-full px-3 py-1.5 text-left text-sm hover:bg-surface-hover';
                    button.textContent = label;
                    button.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                        command([token, label]);
                    });
                    popup.appendChild(button);
                });
            };

            return {
                onStart: (props) => {
                    popup = document.createElement('div');
                    popup.className = 'fixed z-50 min-w-[12rem] rounded-md border border-border bg-surface py-1 shadow-lg';
                    document.body.appendChild(popup);
                    build(props.items, props.command);
                    position(popup, props.clientRect);
                },
                onUpdate: (props) => {
                    build(props.items, props.command);
                    position(popup, props.clientRect);
                },
                onKeyDown: (props) => {
                    if (props.event.key === 'Escape') {
                        popup?.remove();

                        return true;
                    }

                    return false;
                },
                onExit: () => {
                    popup?.remove();
                },
            };
        },
    };
}

function position(popup, clientRect) {
    const rect = clientRect?.();

    if (!rect) return;

    popup.style.left = `${rect.left}px`;
    popup.style.top = `${rect.bottom + 4}px`;
}

const FieldTokenSuggestion = Extension.create({
    name: 'fieldTokenSuggestion',

    addOptions() {
        return { suggestion: { char: '@' } };
    },

    addProseMirrorPlugins() {
        return [Suggestion({ editor: this.editor, ...this.options.suggestion })];
    },
});

/**
 * The pair of extensions a document editor needs to offer field chips:
 * the inline atom node itself, plus the `@`-triggered picker that inserts
 * one. `fields` is `{token: label}`, as DocumentComposer::availableTokens()
 * reports for whatever ERP record the document is linked to — empty for an
 * unlinked document, in which case `@` simply finds nothing to suggest.
 */
export function fieldTokenExtensions(fields) {
    return [
        FieldToken,
        FieldTokenSuggestion.configure({ suggestion: fieldTokenSuggestionOptions(fields) }),
    ];
}
