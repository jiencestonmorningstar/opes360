import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';

/**
 * Stable block ids — §8.3's "anchors comment threads directly to explicit
 * block elements". Every paragraph, heading, list item and table row gets a
 * `data-block-id` the moment it exists, assigned once and kept for the life
 * of the block (splitting a paragraph gives the new half a fresh id rather
 * than cloning the old one, so a comment never silently jumps to text it
 * was never actually about).
 *
 * The id itself carries no meaning — it only has to be stable and unique
 * within a document, so a comment's `anchor_id` keeps pointing at the same
 * block across edits, reloads and reopens.
 */
const ANCHORED_TYPES = ['paragraph', 'heading', 'listItem', 'tableRow'];

function newBlockId() {
    return 'b' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
}

export const BlockAnchor = Extension.create({
    name: 'blockAnchor',

    addGlobalAttributes() {
        return [
            {
                types: ANCHORED_TYPES,
                attributes: {
                    blockId: {
                        default: null,
                        parseHTML: (el) => el.getAttribute('data-block-id'),
                        renderHTML: (attrs) => (attrs.blockId ? { 'data-block-id': attrs.blockId } : {}),
                    },
                },
            },
        ];
    },

    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: new PluginKey('blockAnchorAssign'),
                appendTransaction: (transactions, oldState, newState) => {
                    if (!transactions.some((tr) => tr.docChanged)) return null;

                    let tr = null;

                    newState.doc.descendants((node, pos) => {
                        if (!ANCHORED_TYPES.includes(node.type.name) || node.attrs.blockId) return;

                        tr = (tr || newState.tr).setNodeMarkup(pos, undefined, {
                            ...node.attrs,
                            blockId: newBlockId(),
                        });
                    });

                    return tr;
                },
            }),
        ];
    },

    /** The block id at (or just before) the current selection, if any. */
    addStorage() {
        return {
            currentBlockId: () => {
                const { $from } = this.editor.state.selection;

                for (let depth = $from.depth; depth >= 0; depth--) {
                    const node = $from.node(depth);

                    if (ANCHORED_TYPES.includes(node.type.name) && node.attrs.blockId) {
                        return node.attrs.blockId;
                    }
                }

                return null;
            },
        };
    },
});
