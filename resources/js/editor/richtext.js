import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TableKit } from '@tiptap/extension-table';
import { fieldTokenExtensions } from './field-token.js';
import { BlockAnchor } from './block-anchor.js';

/**
 * The Tiptap island for the papers rich editor.
 *
 * An Alpine component so it plays by Livewire's rules: the editor owns its DOM
 * inside a wire:ignore boundary, and talks to the server only through $wire —
 * a debounced save a few seconds after typing pauses, so a burst of writing
 * costs one request, not one per keystroke. The server is the authority on
 * what HTML is allowed (HtmlSanitizer); this side only produces it.
 */
export default function richEditor({ content, editable, availableTokens = {} }) {
    return {
        editor: null,
        editable,
        availableTokens,
        saveTimer: null,
        /** Bumped on every transaction so Alpine re-evaluates isActive(). */
        tick: 0,

        buttons: [
            { label: 'Heading 1', text: 'H1', action: ['toggleHeading', { level: 1 }], active: ['heading', { level: 1 }] },
            { label: 'Heading 2', text: 'H2', action: ['toggleHeading', { level: 2 }], active: ['heading', { level: 2 }] },
            { label: 'Bold', text: 'B', action: ['toggleBold'], active: ['bold'] },
            { label: 'Italic', text: 'I', action: ['toggleItalic'], active: ['italic'] },
            { label: 'Underline', text: 'U', action: ['toggleUnderline'], active: ['underline'] },
            { label: 'Bullet list', text: '• List', action: ['toggleBulletList'], active: ['bulletList'] },
            { label: 'Numbered list', text: '1. List', action: ['toggleOrderedList'], active: ['orderedList'] },
            { label: 'Blockquote', text: '❝', action: ['toggleBlockquote'], active: ['blockquote'] },
            { label: 'Table', text: 'Table', action: ['insertTable', { rows: 3, cols: 3, withHeaderRow: true }], active: ['table'] },
            { label: 'Horizontal rule', text: '—', action: ['setHorizontalRule'], active: null },
            { label: 'Link', text: 'Link', action: 'link', active: ['link'] },
            { label: 'Undo', text: 'Undo', action: ['undo'], active: null },
        ],

        init() {
            this.editor = new Editor({
                element: this.$refs.editor,
                editable: this.editable,
                content,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [1, 2, 3] },
                        link: {
                            openOnClick: false,
                            // Mirrors the server's HtmlSanitizer; the server
                            // still enforces it regardless.
                            HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
                        },
                    }),
                    TableKit.configure({ table: { resizable: false } }),
                    ...fieldTokenExtensions(this.availableTokens),
                    BlockAnchor,
                ],
                onTransaction: () => { this.tick++; },
                onUpdate: () => this.queueSave(),
            });

            // The server can revoke editing (lost lock) or content (restore).
            this.$watch('$wire.editable', (value) => {
                this.editable = value;
                this.editor.setEditable(value);
            });

            // A last flush when the tab goes away, so a closed laptop lid
            // costs at most the debounce window.
            window.addEventListener('pagehide', () => this.flush());
        },

        destroy() {
            this.editor?.destroy();
        },

        queueSave() {
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.flush(), 2500);
        },

        flush() {
            clearTimeout(this.saveTimer);

            if (!this.editable) return;

            this.$wire.set('body', this.editor.getHTML(), false);
            this.$wire.save();
        },

        setContent(html) {
            this.editor.commands.setContent(html, { emitUpdate: false });
        },

        /**
         * "Comment on this paragraph" — pins the next comment to whatever
         * block the caret is currently in (§8.3). The block already has a
         * stable id by the time this runs; BlockAnchor assigns one to every
         * paragraph/heading/list-item/table-row as soon as it exists.
         */
        commentOnCurrentBlock() {
            const blockId = this.editor?.storage.blockAnchor.currentBlockId();

            this.$wire.anchorNextCommentTo(blockId);
        },

        run(action) {
            if (action === 'link') {
                const previous = this.editor.getAttributes('link').href || '';
                const url = window.prompt('Link address', previous);

                if (url === null) return;

                if (url === '') {
                    this.editor.chain().focus().unsetLink().run();
                } else {
                    this.editor.chain().focus().setLink({ href: url }).run();
                }

                return;
            }

            const [command, options] = action;
            this.editor.chain().focus()[command](options).run();
        },

        isActive(button) {
            this.tick; // dependency: re-evaluate on every transaction

            if (!button.active) return false;

            const [name, attrs] = button.active;

            return this.editor?.isActive(name, attrs) ?? false;
        },
    };
}
