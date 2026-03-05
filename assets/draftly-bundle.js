import { EditorView } from "@codemirror/view";
import { EditorState } from "@codemirror/state";
import { draftly } from "draftly";
import {
    HeadingPlugin,
    InlinePlugin,
    ListPlugin,
    LinkPlugin,
    QuotePlugin,
    HRPlugin,
    ParagraphPlugin,
} from "draftly/plugins";

const plugins = [
    new HeadingPlugin(),
    new InlinePlugin(),
    new ListPlugin(),
    new LinkPlugin(),
    new QuotePlugin(),
    new HRPlugin(),
    new ParagraphPlugin(),
];

window.DraftlyEditor = {
    create(element, markdown = '') {
        const view = new EditorView({
            state: EditorState.create({
                doc: markdown,
                extensions: [
                    draftly({
                        lineWrapping: true,
                        plugins: plugins,
                    }),
                    EditorView.theme({
                        '&': { minHeight: '400px', fontSize: '14px' },
                        '.cm-content': { padding: '16px', fontFamily: 'Inter, sans-serif' },
                        '.cm-editor': { borderRadius: '8px' },
                        '.cm-focused': { outline: 'none' },
                    }),
                ],
            }),
            parent: element,
        });

        return {
            view,
            getMarkdown() {
                return view.state.doc.toString();
            },
            setMarkdown(md) {
                view.dispatch({
                    changes: { from: 0, to: view.state.doc.length, insert: md },
                });
            },
        };
    },
};
