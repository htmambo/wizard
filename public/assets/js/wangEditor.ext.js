const E = window.wangEditor;

class SplitPageMenu extends E.BtnMenu {
    constructor(editor) {
        // data-title属性表示当鼠标悬停在该按钮上时提示该按钮的功能简述
        const $elem = E.$(
            `<div class="w-e-menu" data-title="分页符"><i class="fa fa-newspaper-o"></i></div>`
        )
        super($elem, editor)
    }
    // 菜单点击事件
    clickHandler() {
        const editor = this.editor
        const range = editor.selection.getRange()
        const $selectionElem = editor.selection.getSelectionContainerElem()
        if (!$selectionElem?.length) return
        const $DomElement = E.$($selectionElem.elems[0])
        const $tableDOM = $DomElement.parentUntil('TABLE', $selectionElem.elems[0])
        const $imgDOM = $DomElement.children()
        const $parentElementName = $DomElement.parent().getNodeName()
        // 禁止在代码块中添加分割线
        if($parentElementName === 'CODE' || $parentElementName === 'PRE' || $parentElementName === 'XMP') return;
        if ($DomElement.getNodeName() === 'CODE' || $DomElement.getNodeName() === 'PRE' || $DomElement.getNodeName() === 'XMP') return;
        // 禁止在表格中添加分割线
        if ($tableDOM && E.$($tableDOM.elems[0]).getNodeName() === 'TABLE') return;
        // 禁止在图片处添加分割线
        if (
            $imgDOM &&
            $imgDOM.length !== 0 &&
            E.$($imgDOM.elems[0]).getNodeName() === 'IMG' &&
            !range?.collapsed // 处理光标在 img 后面的情况
        ) {
            return
        }
        // 防止插入分割线时没有占位元素的尴尬
        let splitPageDOM = `<hr style="page-break-after:always;" class="page-break editormd-page-break"><p data-we-empty-p=""><br></p>`
        this.editor.cmd.do('insertHTML', splitPageDOM)
    }

    /**
     * 尝试修改菜单激活状态
     */
    tryChangeActive() {}
}
