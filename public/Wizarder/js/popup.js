import { Common } from './common.js';

const PopupController = function () {
    this.mainCard = document.getElementById('main-card');
    this.errorToast = document.getElementById('error-toast');
    this.infoToast = document.getElementById('info-toast');
    this.cardTitle = document.getElementById('card-title');
    this.entryUrl = document.getElementById('entry-url');
    this.cardImage = document.getElementById('card-image');
    this.tagsInputContainer = document.getElementById('tags-input-container');
    this.tagsInput = document.getElementById('tags-input');
    this.tagsAutoCompleteList = document.getElementById('tags-autocomplete-list');
    this.editIcon = document.getElementById('edit-icon');
    this.saveTitleButton = document.getElementById('save-title-button');
    this.cancelTitleButton = document.getElementById('cancel-title-button');
    this.deleteIcon = document.getElementById('delete-icon');
    this.closeConfirmation = document.getElementById('close-confirmation');
    this.cancelConfirmation = document.getElementById('cancel-confirmation');
    this.deleteArticleButton = document.getElementById('delete-article');
    this.deleteConfirmationCard = document.getElementById('delete_confirmation');
    this.titleInput = document.getElementById('title-input');
    this.cardHeader = document.getElementById('card-header');
    this.cardBody = document.getElementById('card-body');
    this.articleId = -1;
    this.addListeners();
};

PopupController.prototype = {

    mainCard: null,
    errorToast: null,
    infoToast: null,
    apiUrl: null,
    entryUrl: null,
    cardTitle: null,
    cardImage: null,
    tagsInputContainer: null,
    tagsInput: null,
    tagsAutoCompleteList: null,

    articleId: null,
    editIcon: null,
    saveTitleButton: null,
    cancelTitleButton: null,
    deleteIcon: null,
    closeConfirmation: null,
    cancelConfirmation: null,
    deleteArticleButton: null,
    deleteConfirmationCard: null,
    titleInput: null,
    cardHeader: null,
    cardBody: null,

    articleTags: [],
    allTags: [],
    dirtyTags: [],
    foundTags: [],

    allProjects: [],
    tmpTagId: 0,
    AllowSpaceInTags: false,
    AutoAddSingleTag: false,
    tabUrl: null,

    port: null,

    encodeMap: { '&': '&amp;', '\'': '&#039;', '"': '&quot;', '<': '&lt;', '>': '&gt;' },
    decodeMap: { '&amp;': '&', '&#039;': '\'', '&quot;': '"', '&lt;': '<', '&gt;': '>' },

    selectedTag: -1,
    selectedFoundTag: 0,
    backspacePressed: false,

    getSaveHtml: function (param) {
        return param.replace(/[<'&">]/g, symb => this.encodeMap[symb]);
    },

    decodeStr: function (param) {
        for (const prop in this.decodeMap) {
            const propRegExp = new RegExp(prop, 'g');
            param = param.replace(propRegExp, this.decodeMap[prop]);
        }
        return param;
    },

    addListeners: function () {
        this.cardTitle.addEventListener('click', this.openUrl);
        this.editIcon.addEventListener('click', this.editIconClick.bind(this));
        this.saveTitleButton.addEventListener('click', this.saveTitleClick.bind(this));
        this.cancelTitleButton.addEventListener('click', this.cancelTitleClick.bind(this));

        this.deleteIcon.addEventListener('click', this.deleteConfirmation.bind(this));
        this.closeConfirmation.addEventListener('click', this.cancelDelete.bind(this));
        this.cancelConfirmation.addEventListener('click', this.cancelDelete.bind(this));
        this.deleteArticleButton.addEventListener('click', this.deleteArticle.bind(this));

        this.tagsInput.addEventListener('input', this.onTagsInputChanged.bind(this));
        this.tagsInput.addEventListener('keyup', this.onTagsInputKeyUp.bind(this));
        this.tagsInput.addEventListener('keydown', this.onTagsInputKeyDown.bind(this));
    },

    onTagsInputKeyDown: function (event) {
        if (event.key === 'Backspace') this.backspacePressed = true;
        if ((event.key === 'Backspace') && (event.target.value === '')) {
            const lastChip = event.target.previousElementSibling;
            if (lastChip.classList.contains('chip')) {
                const cross = lastChip.childNodes[1];
                if (cross.classList.contains('btn-clear')) {
                    const s = lastChip.dataset.tagname;
                    this.tagsInput.value = s + '!';
                    cross.click();
                }
            }
        }
        if (((event.key === 'ArrowLeft') || (event.key === 'Left')) && (this.selectedFoundTag === 0)) {
            this.selectPreviousTag();
        }
        if (((event.key === 'ArrowRight') || (event.key === 'Right')) && (this.selectedFoundTag === 0)) {
            this.selectNextTag();
        }
        if ((this.selectedTag >= 0) && (event.key === 'Delete')) {
            this.DeleteSelectedTag();
        }
    },

    onTagsInputKeyUp: function (event) {
        if ((event.key === 'ArrowRight') || (event.key === 'Right')) {
            if (!event.ctrlKey) { this.addFoundTag(this.selectedFoundTag); } else {
                if ((this.foundTags.length > 1) && (this.selectedFoundTag < this.foundTags.length - 1)) {
                    this.selectNextFoundTag();
                }
            };
        }
        if (((event.key === 'ArrowLeft') || (event.key === 'Left')) && (event.ctrlKey)) {
            if ((this.foundTags.length > 1) && (this.selectedFoundTag > 0)) {
                this.selectPreviousFoundTag();
            }
        }
        if (event.key === 'Enter') {
            if (this.selectedFoundTag > 0) {
                this.addFoundTag(this.selectedFoundTag);
            } else {
                if (this.tagsInput.value.trim() !== '') {
                    this.addTag(this.tmpTagId, this.tagsInput.value.trim());
                }
            }
        };
    },

    disableTagsInput: function () {
        this.foundTags.length = 0;
        this.tagsInput.value = '';
        this.tagsInput.placeholder = Common.translate('Saving_tags');
        this.tagsInput.disabled = true;
    },

    enableTagsInput: function () {
        this.tagsInput.placeholder = Common.translate('Enter_your_tags_here');
        this.tagsInput.disabled = false;
        this.tagsInput.focus();
    },

    onFoundTagChipClick: function (event) {
        this.addTag(event.currentTarget.dataset.tagid, event.currentTarget.dataset.tagname);
        event.currentTarget.parentNode.removeChild(event.currentTarget);
    },

    addFirstFoundTag: function () {
        if (this.foundTags.length > 0) {
            this.addTag(this.foundTags[0].id, this.foundTags[0].name);
        }
    },

    addFoundTag: function (index) {
        if (this.foundTags.length > 0) {
            this.addTag(this.foundTags[index].id, this.foundTags[index].name);
        }
    },

    addTag: function (tagid, tagname) {
        this.disableTagsInput();
        if (this.articleTags.concat(this.dirtyTags).map(t => t.name.toUpperCase()).indexOf(tagname.toUpperCase()) === -1) {
            this.dirtyTags.push({
                id: tagid,
                name: tagname
            });
            this.tagsInputContainer.insertBefore(
                this.createTagChip(tagid, tagname),
                this.tagsInput);
            this.enableTagsInput();
            if (tagid <= 0) {
                this.tmpTagId = this.tmpTagId - 1;
            }
            this.port.postMessage({ request: 'saveTags', articleId: this.articleId, tags: this.getSaveHtml(this.getTagsStr()), tabUrl: this.tabUrl });
            this.checkAutocompleteState();
        } else {
            this.tagsInput.placeholder = Common.translate('Tag_already_exists');
            const self = this;
            setTimeout(function () { self.enableTagsInput(); }, 1000);
        }
        this.selectedFoundTag = 0;
        this.selectedTag = -1;
    },

    deleteChip: function (ev) {
        const chip = ev.currentTarget.parentNode;
        this.deleteTag(chip);
    },

    DeleteSelectedTag: function () {
        const chip = this.tagsInputContainer.children[this.selectedTag + 1];
        this.deleteTag(chip);
        this.selectedTag = -1;
    },

    deleteTag: function (chip) {
        const tagid = chip.dataset.tagid;
        this.dirtyTags = this.dirtyTags.filter(tag => tag.id !== tagid);
        chip.parentNode.removeChild(chip);
        this.port.postMessage({ request: 'deleteArticleTag', articleId: this.articleId, tagId: tagid, tags: this.getSaveHtml(this.getTagsStr()), tabUrl: this.tabUrl });
        this.checkAutocompleteState();
        this.tagsInput.focus();
    },

    getTagsStr: function () {
        return Array.prototype.slice.call(this.tagsInputContainer.childNodes)
            .filter(e => (e.classList != null) && e.classList.contains('chip'))
            .map(e => e.dataset.tagname).join(',');
    },

    clearAutocompleteList: function () {
        this.foundTags.length = 0;

        Array.prototype.slice.call(this.tagsAutoCompleteList.childNodes)
            .filter(e => (e.classList != null) && e.classList.contains('chip'))
            .map(e => this.tagsAutoCompleteList.removeChild(e));
    },

    findTags: function (search) {
        this.foundTags = this.allTags.filter(tag =>
            (
                (this.articleTags.concat(this.dirtyTags).map(t => t.id).indexOf(tag.id) === -1) &&
                (this.tagsInput.value.length >= 3 &&
                tag.name.toUpperCase().indexOf(this.tagsInput.value.toUpperCase()) !== -1)
            ) ||
            (
                (this.tagsInput.value === tag.name) &&
                (this.articleTags.concat(this.dirtyTags).map(t => t.name).indexOf(this.tagsInput.value) === -1)
            )
        );

        this.foundTags.map(tag => this.tagsAutoCompleteList.appendChild(this.createTagChipNoClose(tag.id, tag.name)));
        if (this.foundTags.length > 2) {
            this.selectFoundTag(0);
            this.selectedFoundTag = 0;
        }
    },

    selectTag: function (index) {
        [...this.tagsInputContainer.children].map(e => e.classList.remove('chip-selected'));
        if ((index >= 0) && (index < (this.articleTags.length + this.dirtyTags.length))) {
            this.tagsInputContainer.children[index + 1].classList.add('chip-selected');
        }
        this.tagsInput.focus();
    },

    selectPreviousTag: function () {
        if (this.selectedTag === -1) {
            this.selectedTag = this.articleTags.length + this.dirtyTags.length - 1;
            this.selectTag(this.selectedTag);
        } else {
            this.selectTag(--this.selectedTag);
        }
    },

    selectNextTag: function () {
        if (this.selectedTag === -1) { return; }
        if (this.selectedTag === this.articleTags.length + this.dirtyTags.length - 1) {
            this.selectTag(-1);
            this.selectedTag = -1;
        } else {
            this.selectTag(++this.selectedTag);
        }
    },

    selectFoundTag: function (index) {
        for (let i = 0; i < this.tagsAutoCompleteList.children.length; i++) {
            this.tagsAutoCompleteList.children[i].classList.remove('chip-selected');
        }
        this.tagsAutoCompleteList.children[index + 1].classList.add('chip-selected');
    },

    selectNextFoundTag: function () {
        this.selectFoundTag(++this.selectedFoundTag);
    },

    selectPreviousFoundTag: function () {
        this.selectFoundTag(--this.selectedFoundTag);
    },

    checkAutocompleteState: function () {
        if (this.foundTags.length > 0) {
            this.mainCard.classList.add('pb-30');
            this.show(this.tagsAutoCompleteList);
        } else {
            this.mainCard.classList.remove('pb-30');
            this.hide(this.tagsAutoCompleteList);
        }
    },

    onTagsInputChanged: function (e) {
        e.preventDefault();
        if (this.tagsInput.value !== '') {
            const lastChar = this.tagsInput.value.slice(-1);
            const value = this.tagsInput.value.slice(0, -1);
            if ((lastChar === ',') || (lastChar === ';') || ((lastChar === ' ') && (!this.AllowSpaceInTags) && (this.selectedFoundTag <= 0))) {
                if (value !== '') {
                    this.addTag(this.tmpTagId, this.tagsInput.value.slice(0, -1));
                }
                this.tagsInput.value = '';
            } else if ((lastChar === ' ') && (this.selectedFoundTag > 0)) {
                this.addFoundTag(this.selectedFoundTag);
            } else {
                this.clearAutocompleteList();
                this.findTags(this.tagsInput.value);
                if ((!this.backspacePressed) && (this.AutoAddSingleTag) && (this.foundSingleTag())) {
                    this.addFoundTag(this.selectedFoundTag);
                }
            }
            this.backspacePressed = false;
        }
        this.checkAutocompleteState();
    },

    deleteArticle: function (e) {
        e.preventDefault();
        this.port.postMessage({ request: 'deleteArticle', articleId: this.articleId, tabUrl: this.tabUrl });
        this.deleteConfirmationCard.classList.remove('active');
        window.close();
    },

    cancelDelete: function (e) {
        e.preventDefault();
        this.deleteConfirmationCard.classList.remove('active');
    },

    deleteConfirmation: function (e) {
        e.preventDefault();
        this.deleteConfirmationCard.classList.add('active');
    },

    editIconClick: function (e) {
        e.preventDefault();
        if (document.getElementById('project-select').options.length === 0 && this.allProjects.length > 0) {
            this.allProjects.forEach(project => {
                const option = document.createElement('option');
                option.value = project.id;
                option.text = project.name;
                document.getElementById('project-select').appendChild(option);
            });
        }
        document.getElementById('project-select').value = this.entryUrl.dataset.projectId;
        if (this.isHidden(this.cardBody)) {
            this.titleInput.value = this.cardTitle.textContent;
            this.hide(this.cardHeader);
            this.show(this.cardBody);
            this.titleInput.focus();
        } else {
            this.hide(this.cardBody);
            this.show(this.cardHeader);
            this.tagsInput.focus();
        }
    },

    saveTitleClick: function (e) {
        e.preventDefault();
        this.port.postMessage({
            request: 'saveTitle',
            articleId: this.articleId,
            title: this.getSaveHtml(this.titleInput.value),
            tabUrl: this.tabUrl,
            projectId: document.getElementById('project-select').value
        });
        var ps = document.getElementById('project-select');
        var psIndex = ps.selectedIndex;
        this.entryUrl.dataset.projectId = ps.value;
        this.entryUrl.textContent = ps.options[psIndex].text;
        this.cardTitle.textContent = this.titleInput.value;

        this.hide(this.cardBody);
        this.show(this.cardHeader);
    },

    cancelTitleClick: function (e) {
        e.preventDefault();
        this.hide(this.cardBody);
        this.show(this.cardHeader);
        this.tagsInput.focus();
    },

    openUrl: function (e) {
        e.preventDefault();
        chrome.tabs.create({ url: this.href });
        window.close();
    },

    activeTab: function () {
        return new Promise((resolve, reject) => {
            chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
                if (tabs[0] != null) {
                    return resolve(tabs[0]);
                } else {
                    return reject(new Error('active tab not found'));
                }
            });
        });
    },

    _createContainerEl: function (id, name) {
        const container = document.createElement('div');
        container.setAttribute('class', 'chip');
        container.setAttribute('data-tagid', id);
        container.setAttribute('data-tagname', name);
        container.appendChild(this._createTagEl(name));
        return container;
    },

    _createTagEl: (name) => {
        const tag = document.createElement('button');
        tag.setAttribute('class', 'chip-name');
        tag.textContent = name;
        return tag;
    },

    createTagChip: function (id, name) {
        const container = this._createContainerEl(id, name);

        const button = document.createElement('button');
        button.setAttribute('class', 'btn btn-clear');
        button.addEventListener('click', this.deleteChip.bind(this));

        container.appendChild(button);

        return container;
    },

    createTagChipNoClose: function (id, name) {
        const container = this._createContainerEl(id, name);
        container.addEventListener('click', this.onFoundTagChipClick.bind(this));
        container.setAttribute('style', 'cursor: pointer;');
        return container;
    },

    clearTagInput: function () {
        const tagsA = Array.prototype.slice.call(this.tagsInputContainer.childNodes);
        return tagsA.filter(e => (e.classList != null) && e.classList.contains('chip'))
            .map(e => { this.tagsInputContainer.removeChild(e); return 0; });
    },

    createTags: function (data) {
        this.articleTags = data;
        this.dirtyTags = this.dirtyTags.filter(tag => this.articleTags.filter(atag => atag.name.toLowerCase() === tag.name.toLowerCase()).length === 0);
        this.clearTagInput();
        this.articleTags.concat(this.dirtyTags).map(tag => this.tagsInputContainer.insertBefore(this.createTagChip(tag.id, tag.name), this.tagsInput));
    },

    setArticle: function (data) {
        this.articleId = data.id;
        this.articleUrl = data.url;
        if (data.title !== undefined) { this.cardTitle.textContent = this.decodeStr(data.title); }
        this.cardTitle.href = data.id === -1 ? '#' : `${this.apiUrl}${this.articleUrl}`;
        this.entryUrl.textContent = data.project_name;
        this.entryUrl.dataset.projectId = data.project_id;

        if (typeof (data.preview_picture) === 'string' &&
            data.preview_picture.length > 0 &&
            data.preview_picture.indexOf('http') === 0) {
            this.cardImage.classList.remove('card-image--default');
            this.cardImage.src = data.preview_picture;
        }
        if (data.id === -1 && data.tagList !== undefined) {
            this.dirtyTags = data.tagList.split(',').map(tagname => {
                this.tmpTagId = this.tmpTagId - 1;
                return {
                    id: this.tmpTagId,
                    name: tagname
                };
            });
            this.createTags([]);
        } else {
            this.createTags(data.tags);
        }
        this.enableTagsInput();
    },

    messageListener: function (msg) {
        switch (msg.response) {
            case 'info':
                this.showInfo(msg.text);
                break;
            case 'error':
                this.hide(this.infoToast);
                this.hide(this.mainCard);
                this.showError(msg.error.message);
                break;
            case 'title':
                if(msg.hasOwnProperty('url') && msg.url) {
                    this.cardTitle.href = `${this.apiUrl}${msg.url}`;
                }
                break;
            case 'article':
                this.hide(this.infoToast);
                if (msg.article !== null) {
                    this.setArticle(msg.article);
                    this.hide(this.infoToast);
                    this.show(this.mainCard);
                } else {
                    this.showError('Error: empty data!');
                }
                break;
            case 'projects':
                this.allProjects = msg.projects;
                break;
            case 'tags':
                this.allTags = msg.tags;
                break;
            case 'setup':
                this.AllowSpaceInTags = msg.data.AllowSpaceInTags || 0;
                this.AutoAddSingleTag = msg.data.AutoAddSingleTag || 0;
                this.apiUrl = msg.data.Url;
                this.afterSetup();
                break;
            case 'articleTags':
                this.createTags(msg.tags);
                break;
            case 'close':
                window.close();
                break;
            default:
                console.log(`unknown message: ${msg}`);
        };
    },

    init: function () {
        this.port = chrome.runtime.connect({ name: 'popup' });
        this.port.onMessage.addListener(this.messageListener.bind(this));
        this.port.postMessage({ request: 'setup' });
    },

    showError: function (infoString) {
        this.errorToast.textContent = infoString;
        this.show(this.errorToast);
    },

    showInfo: function (infoString) {
        this.infoToast.textContent = infoString;
        this.show(this.infoToast);
    },

    hide: function (element) {
        element.classList.add('d-hide');
    },

    show: function (element) {
        element.classList.remove('d-hide');
    },

    isHidden: function (element) {
        return element.classList.contains('d-hide');
    },

    afterSetup: function () {
        this.port.postMessage({ request: 'tags' });
        this.port.postMessage({ request: 'projects' });
        this.saveArticle();
    },

    saveArticle: function () {
        this.activeTab().then(tab => {
            this.tabUrl = tab.url;
            this.cardTitle.textContent = tab.title;
            this.enableTagsInput();

            chrome.runtime.onMessage.addListener(event => {
                if (typeof event.wallabagSaveArticleContent === 'undefined') {
                    return;
                }

                this.port.postMessage({ request: 'save', tabUrl: tab.url, title: tab.title, content: event.wallabagSaveArticleContent });
            });

            chrome.scripting.executeScript({
                target: { tabId: tab.id },
                func: () => {
                    if (typeof (browser) === 'undefined' && typeof (chrome) === 'object') {
                        browser = chrome;
                    }
                    // 删除一些不可能是正文的元素
                    var origDocument = window.document.cloneNode(true);
                    var elements = ['comment', 'header', 'footer', 'nav', 'aside', 'script', 'style', 'link'];
                    elements.forEach(element => {
                        var elements = origDocument.getElementsByTagName(element);
                        for (var i = 0; i < elements.length; i++) {
                            elements[i].parentNode.removeChild(elements[i]);
                        }
                    });
                    // 除了pre和code外，移除其它标签的不必要的属性
                    var elements = origDocument.getElementsByTagName('*');
                    for (var i = 0; i < elements.length; i++) {
                        var element = elements[i];
                        if (element.tagName !== 'PRE' && element.tagName !== 'CODE') {
                            element.removeAttribute('class');
                        }
                        element.removeAttribute('id');
                        element.removeAttribute('style');
                        element.removeAttribute('title');
                    }
                    // 使用正则移除所有的<!-- -->注释
                    var re = /<!--[\s\S]*?-->/g;
                    origDocument.documentElement.innerHTML = origDocument.documentElement.innerHTML.replace(re, '');
                    origDocument.documentElement.innerHTML = origDocument.documentElement.innerHTML.replaceAll('<span>', '').replaceAll('</span>', '');

                    var parser = new DOMParser();
                    origDocument = parser.parseFromString(origDocument.documentElement.outerHTML, 'text/html');
                    var elements = ['header', 'footer', 'nav', 'aside', 'script', 'style', 'link'];
                    elements.forEach(element => {
                        var elements = origDocument.getElementsByTagName(element);
                        while (elements.length > 0) {
                            elements[0].parentNode.removeChild(elements[0]);
                        }
                    });

                    browser.runtime.sendMessage({
                        wallabagSaveArticleContent: origDocument.documentElement.innerHTML
                    });
                }
            });
        });
    },

    foundSingleTag: function () {
        return this.foundTags.length === 1;
    }

};

document.addEventListener('DOMContentLoaded', function () {
    Common.translateAll();
    const PC = new PopupController();
    PC.init();
});
