/* Schema Studio interface translation. English is the source language; every visible string is
 * looked up (exact match) in window.STUDIO.i18n, so the minified designer code needs no changes.
 * Text typed or generated for the user's schema (table and column names) is never translated. */
(function () {
    'use strict';

    var dict = (window.STUDIO && window.STUDIO.i18n) || {};
    var ATTRS = ['placeholder', 'title', 'aria-label'];
    var TEXT_SKIP = '[data-i18n-skip],script,style,textarea,pre,code,#cards,#db-list';
    var ATTR_SKIP = '[data-i18n-skip],script,style,#db-list';
    var texts = new WeakMap();
    var attrs = new WeakMap();
    var lang = 'en';
    var observer = null;
    var attrSelector = ATTRS.map(function (a) { return '[' + a + ']'; }).join(',');

    function translate(value) {
        var key = value.trim();
        if (!key || !Object.prototype.hasOwnProperty.call(dict, key)) return value;

        return value.replace(key, function () { return dict[key]; });
    }

    function skipped(node, selector) {
        var el = node.nodeType === 1 ? node : node.parentElement;

        return !el || !!el.closest(selector);
    }

    function applyText(node) {
        if (skipped(node, TEXT_SKIP)) return;
        var translated = translate(node.nodeValue);
        if (translated !== node.nodeValue) {
            texts.set(node, node.nodeValue);
            node.nodeValue = translated;
        }
    }

    function applyAttr(el, name) {
        if (skipped(el, ATTR_SKIP) || !el.hasAttribute(name)) return;
        var translated = translate(el.getAttribute(name));
        if (translated === el.getAttribute(name)) return;
        var saved = attrs.get(el) || {};
        saved[name] = el.getAttribute(name);
        attrs.set(el, saved);
        el.setAttribute(name, translated);
    }

    function visit(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        var node;
        while ((node = walker.nextNode())) applyText(node);
        var elements = root.nodeType === 1 ? [root].concat([].slice.call(root.querySelectorAll(attrSelector))) : [];
        elements.forEach(function (el) { ATTRS.forEach(function (name) { applyAttr(el, name); }); });
    }

    function revert() {
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        var node;
        while ((node = walker.nextNode())) {
            if (texts.has(node)) { node.nodeValue = texts.get(node); texts.delete(node); }
        }
        [].slice.call(document.querySelectorAll(attrSelector)).forEach(function (el) {
            var saved = attrs.get(el);
            if (!saved) return;
            Object.keys(saved).forEach(function (name) { el.setAttribute(name, saved[name]); });
            attrs.delete(el);
        });
    }

    function observe() {
        if (observer || !window.MutationObserver) return;
        observer = new MutationObserver(function (records) {
            if (lang !== 'ar') return;
            records.forEach(function (record) {
                if (record.type === 'childList') {
                    [].slice.call(record.addedNodes).forEach(function (node) {
                        if (node.nodeType === 1) visit(node); else if (node.nodeType === 3) applyText(node);
                    });
                } else if (record.type === 'characterData') applyText(record.target);
                else if (record.type === 'attributes') applyAttr(record.target, record.attributeName);
            });
        });
        observer.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
    }

    function layout(arabic) {
        var shell = document.querySelector('.shell');
        if (shell) shell.setAttribute('dir', arabic ? 'rtl' : 'ltr');
        // The canvas is positioned with absolute coordinates, so it always stays left-to-right.
        var canvas = document.querySelector('.canvas-wrap');
        if (canvas) canvas.setAttribute('dir', 'ltr');
        document.documentElement.setAttribute('lang', arabic ? 'ar' : 'en');
        var toggle = document.getElementById('lang-toggle');
        if (toggle) toggle.textContent = arabic ? 'English' : 'العربية';
    }

    function setLang(next, remember) {
        lang = next === 'ar' ? 'ar' : 'en';
        if (lang === 'ar') { visit(document.body); observe(); } else revert();
        layout(lang === 'ar');
        if (remember) { try { localStorage.setItem('studio-lang', lang); } catch (e) {} }
    }

    window.StudioI18n = {
        /** Translates a runtime message into the current language (identity in English). */
        t: function (text) { return lang === 'ar' ? translate(text) : text; },
        set: setLang,
        get: function () { return lang; },
        translate: translate
    };

    var saved = null;
    try { saved = localStorage.getItem('studio-lang'); } catch (e) {}
    setLang(saved || (window.STUDIO && window.STUDIO.locale) || 'en', false);

    var toggle = document.getElementById('lang-toggle');
    if (toggle) toggle.addEventListener('click', function () { setLang(lang === 'ar' ? 'en' : 'ar', true); });
})();
