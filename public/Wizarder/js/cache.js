'use strict';

const CacheType = function (enable) {
    this.enabled = enable;
    this._cache = [];
};

CacheType.prototype = {
    _cache: null,
    enabled: false,

    str: function (some) {
        return btoa(unescape(encodeURIComponent(some)));
    },

    set: function (key, data) {
        if (this.enabled) {
            this._cache[this.str(key)] = data;
        }
    },

    clear: function (key) {
        if (this.enabled) {
            delete this._cache[this.str(key)];
        }
    },

    check: function (key) {
        return this.enabled && (this._cache[this.str(key)] !== undefined);
    },

    get: function (key) {
        return this.enabled ? this._cache[this.str(key)] : undefined;
    }
};

export { CacheType };
