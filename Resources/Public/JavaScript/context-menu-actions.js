import Viewport from"@typo3/backend/viewport.js";
/**
 * Module: @t3docs/examples/context-menu-actions
 *
 * JavaScript to handle the click action of the "Hello World" context menu item
 */

class ContextMenuActions {

    hdtranslator_export_page(table, uid, dataset) {
      Viewport.ContentContainer.setUrl(dataset.url)
    };
}

export default new ContextMenuActions();
