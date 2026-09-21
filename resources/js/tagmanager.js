/* ===================================================
 * tagmanager.js (jQuery-free rewrite for Wizard / Bootstrap 5)
 *
 * Original: Max Favilli, Tag Manager v3.0.2
 *   http://welldonethings.com/tags/manager
 *   Licensed under MPL 2.0
 *
 * This rewrite preserves the public behaviour but removes the
 * dependency on jQuery. The public surface is exposed as
 *
 *   window.wizardTagmanager(selector, method, ...args)
 *
 * and a thin alias
 *
 *   window.tagmanager = (...args) => window.wizardTagmanager(...args)
 *
 * where `selector` is a CSS string, a single Element, or an
 * Element collection. Supported methods: init, pushTag, popTag,
 * empty, tags, destroy. Custom events are dispatched as real
 * DOM CustomEvents (bubbles: true) named tm:pushing / tm:pushed
 * / tm:popping / tm:popped / tm:splicing / tm:spliced /
 * tm:duplicated / tm:invalid / tm:refresh / tm:show / tm:hide /
 * tm:emptied. The payload lives at `event.detail` (Array).
 *
 * Wired in by `resources/js/app.js`:
 *
 *     import './tagmanager.js';
 * ========================================================== */

(function () {
  'use strict';

  var NAMESPACE = 'wizardTagmanager';
  var DATA_KEY = '__wizardTagmanager__';

  var defaults = {
    prefilled: null,
    CapitalizeFirstLetter: false,
    preventSubmitOnEnter: true,
    isClearInputOnEsc: true,
    externalTagId: false,
    prefillIdFieldName: 'Id',
    prefillValueFieldName: 'Value',
    AjaxPush: null,
    AjaxPushAllTags: null,
    AjaxPushParameters: null,
    delimiters: [9, 13, 44],
    backspace: [8],
    maxTags: 0,
    hiddenTagListName: null,
    hiddenTagListId: null,
    replace: true,
    output: null,
    deleteTagsOnBackspace: true,
    tagsContainer: null,
    tagCloseIcon: 'x',
    tagClass: '',
    validator: null,
    onlyTagList: false,
    tagList: null,
    fillInputOnTagRemove: false,
    AjaxPushDataType: 'json'
  };

  var KEY_NUMS = [9, 13, 17, 18, 19, 37, 38, 39, 40];

  // ---------- helpers ----------

  // raphael.min.js 会重写 Element.prototype,导致 instanceof Element 全面失效,
  // 这里用 nodeType 鸭子检测代替
  function isElement(o) {
    return !!o && o.nodeType === 1;
  }

  function resolveElements(selector) {
    if (selector == null) return [];
    if (typeof selector === 'string') {
      return Array.prototype.slice.call(document.querySelectorAll(selector));
    }
    if (isElement(selector)) return [selector];
    if (Array.isArray(selector)) return selector.filter(Boolean);
    if (selector.length && (isElement(selector[0]) || selector[0] === window)) {
      return Array.prototype.slice.call(selector);
    }
    return [];
  }

  function resolveContainer(ref) {
    if (!ref) return null;
    if (isElement(ref)) return ref;
    if (typeof ref === 'string') return document.querySelector(ref);
    return null;
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  }

  function trimTag(tag, delimiterChars) {
    var s = String(tag == null ? '' : tag).replace(/^\s+|\s+$/g, '');
    for (var i = 0; i < s.length; i++) {
      if (delimiterChars.indexOf(s.charCodeAt(i)) !== -1) break;
    }
    return s.substring(0, i);
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function ajaxPost(url, data, dataType) {
    var body = new URLSearchParams();
    if (data && typeof data === 'object') {
      for (var k in data) {
        if (Object.prototype.hasOwnProperty.call(data, k)) {
          body.set(k, data[k]);
        }
      }
    }
    var headers = { 'X-Requested-With': 'XMLHttpRequest' };
    var token = csrfToken();
    if (token) headers['X-CSRF-TOKEN'] = token;
    headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
    return fetch(url, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: body.toString()
    }).then(function (r) {
      if (dataType === 'json') return r.json().catch(function () { return null; });
      return r.text();
    }).catch(function (err) {
      if (typeof console !== 'undefined' && console.error) {
        console.error('[tagmanager] ajaxPost error:', err);
      }
    });
  }

  function killEvent(e) {
    e.cancelBubble = true;
    e.returnValue = false;
    if (typeof e.stopPropagation === 'function') e.stopPropagation();
    if (typeof e.preventDefault === 'function') e.preventDefault();
  }

  function keyInArray(e, ary) {
    return ary.indexOf(e.which || e.keyCode) !== -1;
  }

  // ---------- per-input controller ----------

  function TagInstance(input, opts) {
    this.input = input;
    this.opts = opts;
    this.tlis = [];
    this.tlid = [];
    this.rndid = '';
    this.lhiddenTagList = null;
    this._bound = {};
    this._prefilled = []; // normalised array

    var albet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    var s = '';
    for (var i = 0; i < 5; i++) {
      s += albet.charAt(Math.floor(Math.random() * albet.length));
    }
    this.rndid = s;
  }

  TagInstance.prototype.trigger = function (type) {
    var args = Array.prototype.slice.call(arguments, 1);
    var ev;
    try {
      ev = new CustomEvent(type, { detail: args, bubbles: true, cancelable: true });
    } catch (e) {
      // IE11 fallback (not a target browser, but defensive)
      ev = document.createEvent('CustomEvent');
      ev.initCustomEvent(type, true, true, args);
    }
    this.input.dispatchEvent(ev);
  };

  TagInstance.prototype.tagEl = function (tagId, tagText) {
    var opts = this.opts;
    var id = this.rndid + '_' + tagId;
    var removeId = this.rndid + '_Remover_' + tagId;
    var classes = this.tagClasses(tagId);

    var span = document.createElement('span');
    span.className = classes;
    span.id = id;

    var inner = document.createElement('span');
    inner.innerHTML = escapeHtml(tagText);
    span.appendChild(inner);

    var remove = document.createElement('a');
    remove.href = '#';
    remove.className = 'tm-tag-remove';
    remove.id = removeId;
    remove.setAttribute('TagIdToRemove', String(tagId));
    remove.innerHTML = opts.tagCloseIcon;
    span.appendChild(remove);

    return span;
  };

  TagInstance.prototype.tagClasses = function (tagId) {
    var opts = this.opts;
    var cl = opts.tagBaseClass;
    var inputClass = this.input.getAttribute('class') || '';
    var inputBase = opts.inputBaseClass;
    inputClass.split(/\s+/).forEach(function (v) {
      if (v && v.indexOf(inputBase + '-') === 0) {
        cl += ' ' + opts.tagBaseClass + v.substring(inputBase.length);
      }
    });
    if (opts.tagClass) {
      var all = String(opts.tagClass).split(/\s+/).filter(Boolean);
      if (all.length) cl += ' ' + all[tagId % all.length];
    }
    return cl;
  };

  TagInstance.prototype.blink = function (el) {
    var opts = this.opts;
    if (!el) return;
    if (opts.blinkClass) {
      var i = 0;
      var iv = setInterval(function () {
        el.classList.toggle(opts.blinkClass);
        if (++i >= 6) clearInterval(iv);
      }, 100);
    } else {
      var colors = [opts.blinkBGColor_1, opts.blinkBGColor_2];
      var step = 0;
      var iv = setInterval(function () {
        el.style.backgroundColor = colors[step % 2];
        if (++step >= 6) clearInterval(iv);
      }, 100);
    }
  };

  TagInstance.prototype.refreshHidden = function () {
    var joined = this.tlis.join(this.opts.baseDelimiter);
    if (this.lhiddenTagList) {
      this.lhiddenTagList.value = joined;
      this.lhiddenTagList.dispatchEvent(new Event('change', { bubbles: true }));
    }
    this.trigger('tm:refresh', joined);
  };

  TagInstance.prototype.showOrHide = function () {
    var opts = this.opts;
    if (opts.maxTags > 0 && this.tlis.length < opts.maxTags) {
      this.input.style.display = '';
      this.trigger('tm:show');
    }
    if (opts.maxTags > 0 && this.tlis.length >= opts.maxTags) {
      this.input.style.display = 'none';
      this.trigger('tm:hide');
    }
  };

  TagInstance.prototype.pushTag = function (tag, ignoreEvents, externalTagId, ignoreValidator) {
    var opts = this.opts;
    tag = trimTag(tag, opts.delimiterChars);
    if (!tag) return;

    if (opts.onlyTagList && Array.isArray(opts.tagList)) {
      var lowered = opts.tagList.map(function (s) { return String(s).toLowerCase(); });
      if (lowered.indexOf(tag.toLowerCase()) === -1) return;
    }

    if (opts.CapitalizeFirstLetter && tag.length > 1) {
      tag = tag.charAt(0).toUpperCase() + tag.slice(1).toLowerCase();
    }

    if (!ignoreValidator && typeof opts.validator === 'function' && !opts.validator(tag)) {
      this.trigger('tm:invalid', tag);
      return;
    }

    if (opts.maxTags > 0 && this.tlis.length >= opts.maxTags) return;

    var lower = this.tlis.map(function (s) { return s.toLowerCase(); });
    var dupIdx = lower.indexOf(tag.toLowerCase());
    if (dupIdx !== -1) {
      this.trigger('tm:duplicated', tag);
      var dupEl = document.getElementById(this.rndid + '_' + this.tlid[dupIdx]);
      this.blink(dupEl);
      this.input.value = '';
      return;
    }

    var tagId;
    if (opts.externalTagId === true) {
      if (externalTagId === undefined) {
        throw new Error('externalTagId is not passed for tag -' + tag);
      }
      tagId = externalTagId;
    } else {
      var max = this.tlid.length ? Math.max.apply(null, this.tlid) : -Infinity;
      tagId = (max === -Infinity ? 0 : max) + 1;
    }

    if (!ignoreEvents) this.trigger('tm:pushing', tag, tagId);

    this.tlis.push(tag);
    this.tlid.push(tagId);

    if (!ignoreEvents && opts.AjaxPush && !opts.AjaxPushAllTags) {
      if (this._prefilled.indexOf(tag) === -1) {
        var params = Object.assign({ tag: tag }, opts.AjaxPushParameters || {});
        ajaxPost(opts.AjaxPush, params, opts.AjaxPushDataType);
      }
    }

    var tagNode = this.tagEl(tagId, tag);

    var typeAheadMess = !!this.input.closest('.twitter-typeahead');
    var container = resolveContainer(opts.tagsContainer);
    if (container) {
      container.appendChild(tagNode);
    } else if (this.tlid.length > 1 && typeAheadMess) {
      var lastId = this.rndid + '_' + (tagId - 1);
      var lastEl = document.getElementById(lastId);
      if (lastEl && lastEl.parentNode) {
        lastEl.parentNode.insertBefore(tagNode, lastEl.nextSibling);
      } else {
        this.input.parentNode.insertBefore(tagNode, this.input);
      }
    } else if (typeAheadMess) {
      var ta = this.input.closest('.twitter-typeahead');
      if (ta && ta.parentNode) ta.parentNode.insertBefore(tagNode, ta);
      else this.input.parentNode.insertBefore(tagNode, this.input);
    } else {
      this.input.parentNode.insertBefore(tagNode, this.input);
    }

    var self = this;
    var removeBtn = document.getElementById(this.rndid + '_Remover_' + tagId);
    if (removeBtn) {
      var onRemove = function (e) {
        e.preventDefault();
        var idToRemove = parseInt(removeBtn.getAttribute('TagIdToRemove'), 10);
        self.spliceTag(idToRemove);
      };
      removeBtn.addEventListener('click', onRemove);
      this._bound['remove_' + tagId] = { node: removeBtn, type: 'click', fn: onRemove };
    }

    this.refreshHidden();
    if (!ignoreEvents) this.trigger('tm:pushed', tag, tagId);
    this.showOrHide();
    this.input.value = '';
  };

  TagInstance.prototype.popTag = function () {
    if (this.tlid.length === 0) return;
    var tagId = this.tlid.pop();
    var tag = this.tlis[this.tlis.length - 1];
    this.trigger('tm:popping', tag, tagId);
    this.tlis.pop();
    var node = document.getElementById(this.rndid + '_' + tagId);
    if (node && node.parentNode) node.parentNode.removeChild(node);
    this.refreshHidden();
    this.trigger('tm:popped', tag, tagId);
    this.showOrHide();
  };

  TagInstance.prototype.empty = function () {
    while (this.tlid.length > 0) {
      var tagId = this.tlid.pop();
      this.tlis.pop();
      var node = document.getElementById(this.rndid + '_' + tagId);
      if (node && node.parentNode) node.parentNode.removeChild(node);
    }
    this.refreshHidden();
    this.trigger('tm:emptied');
    this.showOrHide();
  };

  TagInstance.prototype.tags = function () {
    return this.tlis.slice();
  };

  TagInstance.prototype.spliceTag = function (tagId) {
    var idx = this.tlid.indexOf(tagId);
    if (idx === -1) return;
    var tag = this.tlis[idx];
    this.trigger('tm:splicing', tag, tagId);
    var node = document.getElementById(this.rndid + '_' + tagId);
    if (node && node.parentNode) node.parentNode.removeChild(node);
    this.tlis.splice(idx, 1);
    this.tlid.splice(idx, 1);
    this.refreshHidden();
    this.trigger('tm:spliced', tag, tagId);
    this.showOrHide();
  };

  TagInstance.prototype.pushAllTags = function (e, tag) {
    var opts = this.opts;
    if (!opts.AjaxPushAllTags) return;
    if (e.type !== 'tm:pushed' || this._prefilled.indexOf(tag) === -1) {
      var params = Object.assign(
        { tags: this.tlis.join(opts.baseDelimiter) },
        opts.AjaxPushParameters || {}
      );
      ajaxPost(opts.AjaxPush, params, opts.AjaxPushDataType);
    }
  };

  TagInstance.prototype.prefill = function (pta) {
    var opts = this.opts;
    var list = Array.isArray(pta) ? pta : [pta];
    var self = this;
    list.forEach(function (val) {
      if (
        opts.externalTagId === true &&
        val && typeof val === 'object' && !Array.isArray(val)
      ) {
        self.pushTag(val[opts.prefillValueFieldName], true, val[opts.prefillIdFieldName], true);
      } else {
        self.pushTag(val, true, false, true);
      }
    });
  };

  TagInstance.prototype.bindEvents = function () {
    var opts = this.opts;
    var self = this;
    var input = this.input;

    var onFocusKeypress = function () {
      try {
        if (window.bootstrap && window.bootstrap.Popover) {
          var inst = window.bootstrap.Popover.getInstance(input);
          if (inst && typeof inst.hide === 'function') inst.hide();
        }
      } catch (_) { /* no popover */ }
    };
    input.addEventListener('focus', onFocusKeypress);
    input.addEventListener('keypress', onFocusKeypress);
    this._bound.onFocusKeypress = { node: input, types: ['focus', 'keypress'], fn: onFocusKeypress };

    if (opts.isClearInputOnEsc) {
      var onKeyup = function (e) {
        if ((e.which || e.keyCode) === 27) {
          input.value = '';
          killEvent(e);
        }
      };
      input.addEventListener('keyup', onKeyup);
      this._bound.onKeyup = { node: input, types: ['keyup'], fn: onKeyup };
    }

    var onKeypress = function (e) {
      if (keyInArray(e, opts.delimiterChars)) {
        self.pushTag(input.value);
        killEvent(e);
      }
    };
    input.addEventListener('keypress', onKeypress);
    this._bound.onKeypress = { node: input, types: ['keypress'], fn: onKeypress };

    var onKeydown = function (e) {
      var which = e.which || e.keyCode;
      if (which === 13 && opts.preventSubmitOnEnter) {
        killEvent(e);
      }
      if (keyInArray(e, opts.delimiterKeys)) {
        self.pushTag(input.value);
        killEvent(e);
      }
      if (keyInArray(e, opts.backspace) && input.value.length <= 0) {
        self.popTag();
        killEvent(e);
      }
    };
    input.addEventListener('keydown', onKeydown);
    this._bound.onKeydown = { node: input, types: ['keydown'], fn: onKeydown };

    if (opts.AjaxPushAllTags) {
      var onSpliced = function (e) { self.pushAllTags(e); };
      var onPopped = function (e) {
        var tag = e.detail && e.detail[0];
        self.pushAllTags(e, tag);
      };
      var onPushed = function (e) {
        var tag = e.detail && e.detail[0];
        self.pushAllTags(e, tag);
      };
      input.addEventListener('tm:spliced', onSpliced);
      input.addEventListener('tm:popped', onPopped);
      input.addEventListener('tm:pushed', onPushed);
      this._bound.onSpliced = { node: input, types: ['tm:spliced'], fn: onSpliced };
      this._bound.onPopped = { node: input, types: ['tm:popped'], fn: onPopped };
      this._bound.onPushed = { node: input, types: ['tm:pushed'], fn: onPushed };
    }

    if (opts.fillInputOnTagRemove) {
      var onPoppedFill = function (e) {
        input.value = (e.detail && e.detail[0]) || '';
      };
      input.addEventListener('tm:popped', onPoppedFill);
      this._bound.onPoppedFill = { node: input, types: ['tm:popped'], fn: onPoppedFill };
    }

    var onChange = function (e) {
      if (!/webkit/.test(navigator.userAgent.toLowerCase())) {
        input.focus();
      }
      killEvent(e);
    };
    input.addEventListener('change', onChange);
    this._bound.onChange = { node: input, types: ['change'], fn: onChange };
  };

  TagInstance.prototype.init = function () {
    var opts = this.opts;

    if (opts.output === null) {
      var name = opts.hiddenTagListName === null
        ? 'hidden-' + (this.input.getAttribute('name') || '')
        : opts.hiddenTagListName;
      opts.hiddenTagListName = name;
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = name;
      if (this.input.parentNode) {
        if (this.input.nextSibling) {
          this.input.parentNode.insertBefore(hidden, this.input.nextSibling);
        } else {
          this.input.parentNode.appendChild(hidden);
        }
      }
      this.lhiddenTagList = hidden;
    } else {
      this.lhiddenTagList = resolveContainer(opts.output);
    }

    // normalise prefilled into an array for quick membership tests
    if (opts.prefilled == null) {
      this._prefilled = [];
    } else if (Array.isArray(opts.prefilled)) {
      this._prefilled = opts.prefilled.map(function (v) { return String(v); });
    } else if (typeof opts.prefilled === 'string') {
      this._prefilled = opts.prefilled.split(opts.baseDelimiter);
    } else if (typeof opts.prefilled === 'function') {
      var r = opts.prefilled();
      this._prefilled = Array.isArray(r) ? r.map(String) : (r ? String(r).split(opts.baseDelimiter) : []);
    } else if (typeof opts.prefilled === 'object') {
      this._prefilled = [opts.prefilled];
    } else {
      this._prefilled = [];
    }

    this.bindEvents();

    // initial fill
    if (opts.prefilled != null) {
      this.prefill(opts.prefilled);
    } else if (opts.output !== null && this.lhiddenTagList && this.lhiddenTagList.value) {
      this.prefill(this.lhiddenTagList.value.split(opts.baseDelimiter));
    }
  };

  TagInstance.prototype.destroy = function () {
    var b = this._bound;
    Object.keys(b).forEach(function (k) {
      if (k.indexOf('remove_') === 0) {
        b[k].node.removeEventListener(b[k].type, b[k].fn);
      } else {
        (b[k].types || [b[k].type]).forEach(function (t) {
          b[k].node.removeEventListener(t, b[k].fn);
        });
      }
    });
    this._bound = {};
    delete this.input[DATA_KEY];
  };

  // ---------- public entry ----------

  function initOnInput(input, userOpts) {
    if (input[DATA_KEY]) return input[DATA_KEY];

    var opts = {};
    for (var k in defaults) opts[k] = defaults[k];
    if (userOpts && typeof userOpts === 'object') {
      for (var k2 in userOpts) opts[k2] = userOpts[k2];
    }

    opts.hiddenTagListName = opts.hiddenTagListName === null
      ? 'hidden-' + (input.getAttribute('name') || '')
      : opts.hiddenTagListName;

    var delimiters = opts.delimeters || opts.delimiters; // tolerate legacy typo
    opts.delimiterChars = [];
    opts.delimiterKeys = [];
    delimiters.forEach(function (v) {
      if (KEY_NUMS.indexOf(v) !== -1) opts.delimiterKeys.push(v);
      else opts.delimiterChars.push(v);
    });
    opts.baseDelimiter = String.fromCharCode(opts.delimiterChars[0] || 44);
    opts.tagBaseClass = 'tm-tag';
    opts.inputBaseClass = 'tm-input';

    if (typeof opts.validator !== 'function') opts.validator = null;

    var inst = new TagInstance(input, opts);
    input[DATA_KEY] = inst;
    inst.init();
    return inst;
  }

  function wizardTagmanager(selector, methodOrOptions) {
    var elements = resolveElements(selector);
    if (elements.length === 0) return null;

    var method = methodOrOptions;
    var args = Array.prototype.slice.call(arguments, 2);

    if (method === undefined || (method && typeof method === 'object')) {
      method = 'init';
      if (methodOrOptions !== undefined) args = [methodOrOptions];
    }
    method = String(method);

    if (method === 'init') {
      var results = elements.map(function (el) { return initOnInput(el, args[0]); });
      return results.length === 1 ? results[0] : results;
    }

    var inst = elements[0][DATA_KEY];
    if (!inst) return undefined;

    switch (method) {
      case 'pushTag':
        inst.pushTag.apply(inst, args);
        return inst;
      case 'popTag':
        inst.popTag();
        return inst;
      case 'empty':
        inst.empty();
        return inst;
      case 'tags':
        return inst.tags();
      case 'destroy':
        inst.empty();
        inst.destroy();
        return null;
      default:
        throw new Error('Method ' + method + ' does not exist on wizardTagmanager');
    }
  }

  // Expose public surface
  window[NAMESPACE] = wizardTagmanager;
  window.tagmanager = function () {
    return window[NAMESPACE].apply(window, arguments);
  };

  // Optional jQuery plugin shim — only attached if jQuery happens to be
  // on the page. Allows existing blade code
  //     $(".tm-input").tagsManager({...})
  // to keep working until agent-B rewrites the blade in Stage 3.
  if (window.jQuery && window.jQuery.fn) {
    window.jQuery.fn.tagsManager = function (method) {
      var passArgs = [this].concat(Array.prototype.slice.call(arguments));
      return window[NAMESPACE].apply(window, passArgs);
    };
  }
})();
