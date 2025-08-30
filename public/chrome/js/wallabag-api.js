import { FetchApi } from './fetch-api.js';

/**
 * @param {string} url
 * @returns {Promise<string>}
 * @see https://developer.mozilla.org/en-US/docs/Web/API/SubtleCrypto/digest#converting_a_digest_to_a_hex_string
 */
const hashUrl = function (url) {
    const urlByteArray = new TextEncoder().encode(url);
    return crypto.subtle.digest('SHA-1', urlByteArray).then(hashBuffer => {
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray
            .map((b) => b.toString(16).padStart(2, '0'))
            .join(''); // convert bytes to hex string
        return hashHex;
    });
};

const WallabagApi = function () { };

WallabagApi.prototype = {

    defaultValues: {
        Url: null,
        ApiVersion: null,
        ClientId: null,
        ClientSecret: null,
        UserLogin: null,
        UserPassword: null,
        ApiToken: null,
        RefreshToken: null,
        ExpireDate: 0,
        isTokenExpired: true,
        isFetchPermissionGranted: null,
        AllowSpaceInTags: false,
        AllowExistCheck: false,
        AllowExistSafe: null,
        Debug: false,
        AutoAddSingleTag: false
    },

    data: {},

    fetchApi: null,

    tags: [],

    init: function () {
        Object.assign(this.data, this.defaultValues);
        this.fetchApi = new FetchApi();
        return this.load().then(
            result => {
                this.setAllowExistSafe();
                return Promise.resolve(result);
            }
        );
    },

    resetDebug: function () {
        this.data.Debug = this.defaultValues.Debug;
        this.save();
    },

    save: function () {
        chrome.storage.local.set({ wizarderdata: this.data });
    },

    load: function () {
        return new Promise((resolve, reject) => {
            chrome.storage.local.get('wizarderdata', result => {
                if (result.wizarderdata != null) {
                    this.set(result.wizarderdata);
                    if (this.checkParams()) {
                        resolve(this.data);
                    } else {
                        this.clear();
                        if (this.Debug === true) {
                            console.log('Some parameters are empty. Check the settings');
                        }
                    }
                } else {
                    this.clear();
                    if (this.Debug === true) {
                        console.log('Saved parameters not found. Check the settings');
                    }
                }
            });
        });
    },

    needNewAppToken: function () {
        const need = (
            (this.data.ApiToken === '') ||
                  (this.data.ApiToken === null) ||
                  this.isTokenExpired()
        );
        return need;
    },

    checkParams: function () {
        return ((this.data.ClientId !== null) &&
                 (this.data.ClientSecret !== null) &&
                 (this.data.userLogin !== null) &&
                 (this.data.UserPassword !== null) &&
                 (this.data.ClientId !== '') &&
                 (this.data.ClientSecret !== '') &&
                 (this.data.userLogin !== '') &&
                 (this.data.UserPassword !== ''));
    },

    isTokenExpired: function () {
        return Date.now() > this.data.ExpireDate;
    },

    clear: function () {
        this.set(this.defaultValues);
    },

    set: function (params) {
        Object.assign(this.data, params);
    },

    setsave: function (params) {
        this.set(params);
        this.save();
    },

    CheckUrl: function () {
        const url_ = this.data.Url + '/api/version';
        return this.fetchApi.Get(url_, '')
            .then(fetchData => {
                this.data.ApiVersion = fetchData.version;
                this.setAllowExistSafe();
                this.save();
                return fetchData.version;
            })
            .catch(error => {
                throw new Error(`Failed to get api version ${url_}
                ${error.message}`);
            });
    },

    setAllowExistSafe: async function () {
        if (typeof (this.data.Url) !== 'string') {
            return false;
        }
        this.data.AllowExistSafe = true;
    },

    /**
     * @returns {Promise<[number, number, number]>}
     */
    GetVersion: function () {
        if (this.data.ApiVersion) return Promise.resolve(this.data.ApiVersion.split('.').map(Number));
        return this.CheckUrl().then(() => this.GetVersion());
    },

    SaveTitle: function (articleId, articleTitle, projectId) {
        return this.PatchArticle(articleId, { title: articleTitle, project_id: projectId  });
    },

    SaveTags: function (articleId, taglist) {
        return this.PatchArticle(articleId, { tags: taglist });
    },

    PatchArticle: function (articleId, content) {
        const entryUrl = `${this.data.Url}/api/document/${articleId}.json`;
        return this.CheckToken().then(a =>
            this.fetchApi.Patch(entryUrl, this.data.ApiToken, content)
        )
            .catch(error => {
                throw new Error(`Failed to update article ${entryUrl}
                ${error.message}`);
            });
    },
    /** Delete article
     * @param articleId {number} Article identificator
     */
    DeleteArticle: function (articleId) {
        const entryUrl = `${this.data.Url}/api/document/${articleId}.json`;
        return this.CheckToken().then(a =>
            this.fetchApi.Delete(entryUrl, this.data.ApiToken)
        )
            .catch(error => {
                throw new Error(`Failed to delete article ${entryUrl}
                ${error.message}`);
            });
    },

    DeleteArticleTag: function (articleId, tagid) {
        const entryUrl = `${this.data.Url}/api/document/${articleId}/tags/${tagid}.json`;
        return this.CheckToken().then(a =>
            this.fetchApi.Delete(entryUrl, this.data.ApiToken)
        )
            .catch(error => {
                throw new Error(`Failed to delete article tag ${entryUrl}
                ${error.message}`);
            });
    },

    CheckToken: function () {
        return new Promise((resolve, reject) => {
            if (!this.checkParams()) {
                reject(new Error('Parameters not ok.'));
            }
            if (this.needNewAppToken()) {
                resolve(this.PasswordToken());
            }
            resolve('Token ok.');
        });
    },
    SavePage: function (options) {
        const content = { url: options.url };

        if (options.title) {
            content.title = options.title;
        } else {
            console.error('Title is empty');
        }
        if (options.content) {
            content.content = options.content;
        }
        const entriesUrl = `${this.data.Url}/api/document.json`;
        return this.CheckToken().then(a =>
            this.fetchApi.Post(entriesUrl, this.data.ApiToken, content)
        )
            .catch(error => {
                throw new Error(`Failed to save page ${entriesUrl}
                ${error.message}`);
            });
    },
    GetProjects: function () {
        const entriesUrl = `${this.data.Url}/api/project/lists.json`;
        return this.CheckToken().then(a =>
                this.fetchApi.Get(entriesUrl, this.data.ApiToken)
            )
           .catch(error => {
                throw new Error(`Failed to get project lists ${entriesUrl}
                ${error.message}`);
            });
    },
    RefreshToken: function () {
        const content = {
            grant_type: 'refresh_token',
            refresh_token: this.data.RefreshToken,
            client_id: this.data.ClientId,
            client_secret: this.data.ClientSecret
        };
        return this.GetAppToken(content);
    },

    PasswordToken: function () {
        const content = {
            grant_type: 'password',
            client_id: this.data.ClientId,
            client_secret: this.data.ClientSecret,
            username: this.data.UserLogin,
            password: this.data.UserPassword
        };
        return this.GetAppToken(content);
    },

    GetAppToken: function (content) {
        this.CheckUrl();
        const oauthurl = `${this.data.Url}/api/oauth/token`;
        return this.fetchApi.Post(oauthurl, '', content)
            .then(data => {
                if (data !== '') {
                    this.data.ClientSecret = content.client_secret;
                    this.data.UserPassword = content.password;
                    this.data.UserLogin = content.username;
                    this.data.ClientId = content.client_id;
                    this.data.ApiToken = data.access_token;
                    this.data.RefreshToken = data.refresh_token;
                    this.data.ExpireDate = Date.now() + data.expires_in * 1000;
                    this.data.isTokenExpired = this.isTokenExpired();
                    return data;
                }
            })
            .catch(error => {
                console.error(error);
                throw new Error(`Failed to refresh token ${oauthurl}
                ${error.message}`);
            });
    },

    GetTags: function () {
        if (!this.checkParams()) {
            return false;
        }
        const entriesUrl = `${this.data.Url}/api/tags.json`;
        return this.CheckToken().then(a =>
            this.fetchApi.Get(entriesUrl, this.data.ApiToken)
        )
            .then(fetchData => {
                this.tags = fetchData;
                return fetchData;
            })
            .catch(error => {
                throw new Error(`Failed to get tags ${entriesUrl} ${error.message}`);
            });
    },

    EntryExists: function (url) {
        const existsUrl = `${this.data.Url}/api/document/exists.json`;

        return this.CheckToken().then(() => {
            const paramAsync = Promise.resolve(url);
            return paramAsync.then(param => `${existsUrl}?${'url'}=${encodeURIComponent(param)}`);
        })
            .then(url => this.fetchApi.Get(url, this.data.ApiToken))
            .catch(error => {
                throw new Error(`Failed to ask ${existsUrl} whether ${url} exists
                ${error.message}`);
            });
    },

    GetArticle: function (articleId) {
        const entriesUrl = `${this.data.Url}/api/document/${articleId}.json`;
        return this.CheckToken().then(a =>
            this.fetchApi.Get(entriesUrl, this.data.ApiToken)
        )
            .catch(error => {
                throw new Error(`Failed to get article ${entriesUrl}
                ${error.message}`);
            });
    },

    GetArticleTags: function (articleId) {
        const entriesUrl = `${this.data.Url}/api/document/${articleId}/tags.json`;
        return this.CheckToken().then(a =>
            this.fetchApi.Get(entriesUrl, this.data.ApiToken)
        )
            .catch(error => {
                throw new Error(`Failed to get article tags ${entriesUrl}
                ${error.message}`);
            });
    }
};

export { WallabagApi };
