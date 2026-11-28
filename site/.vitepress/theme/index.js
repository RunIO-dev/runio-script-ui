import DefaultTheme from 'vitepress/theme';
import LiveClick from './live/LiveClick.vue';
import LiveTask from './live/LiveTask.vue';
import './custom.css';
import './live/live.css';

export default {
    extends: DefaultTheme,
    // The live pictures of the first page. A page writes <LiveClick /> or <LiveTask />.
    enhanceApp({ app }) {
        app.component('LiveClick', LiveClick);
        app.component('LiveTask', LiveTask);
    },
};
