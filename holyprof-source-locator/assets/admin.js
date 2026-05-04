(function () {
    'use strict';

    var config = window.holyprofSourceLocatorAdmin || {};
    var form = document.getElementById('holyprof-source-locator-form');
    var resultsContainer = document.getElementById('holyprof-source-locator-results');
    var searchInput = document.getElementById('wsf-search');

    if (!form || !resultsContainer || !config.ajaxUrl || !config.nonce) {
        return;
    }

    if (searchInput) {
        searchInput.focus();
    }

    resultsContainer.addEventListener('click', function (event) {
        var button = event.target.closest('.holyprof-source-locator-copy-button');
        var textToCopy;

        if (!button) {
            return;
        }

        textToCopy = button.getAttribute('data-copy-text') || '';

        if (!textToCopy) {
            return;
        }

        copyText(textToCopy).then(function () {
            var originalLabel = button.textContent;

            button.textContent = 'Copied';

            window.setTimeout(function () {
                button.textContent = originalLabel;
            }, 1200);
        });
    });

    form.addEventListener('submit', function (event) {
        var formData;
        var submitButton;

        event.preventDefault();

        formData = new window.FormData(form);
        formData.append('action', config.action);
        formData.append('nonce', config.nonce);

        submitButton = form.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
        }

        resultsContainer.classList.add('is-loading');
        resultsContainer.innerHTML = [
            '<div class="holyprof-source-locator-results">',
            '<h2>Results</h2>',
            '<div class="holyprof-source-locator-notice"><p>',
            escapeHtml(config.messages.loading || 'Searching files...'),
            '</p></div>',
            '</div>'
        ].join('');

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!payload || !payload.success || !payload.data || !payload.data.html) {
                throw new Error('Invalid AJAX response');
            }

            resultsContainer.innerHTML = payload.data.html;
            if (searchInput) {
                searchInput.focus();
            }
        }).catch(function () {
            resultsContainer.innerHTML = [
                '<div class="holyprof-source-locator-results">',
                '<h2>Results</h2>',
                '<div class="holyprof-source-locator-notice"><p>',
                escapeHtml(config.messages.error || 'Something went wrong while searching. Please try again.'),
                '</p></div>',
                '</div>'
            ].join('');
        }).finally(function () {
            if (submitButton) {
                submitButton.disabled = false;
            }

            resultsContainer.classList.remove('is-loading');
        });
    });

    function escapeHtml(value) {
        var text = document.createElement('textarea');

        text.textContent = value;

        return text.innerHTML;
    }

    function copyText(value) {
        if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
            return window.navigator.clipboard.writeText(value);
        }

        return new window.Promise(function (resolve, reject) {
            var temporaryField = document.createElement('textarea');

            temporaryField.value = value;
            temporaryField.setAttribute('readonly', 'readonly');
            temporaryField.style.position = 'absolute';
            temporaryField.style.left = '-9999px';
            document.body.appendChild(temporaryField);
            temporaryField.select();

            try {
                document.execCommand('copy');
                document.body.removeChild(temporaryField);
                resolve();
            } catch (error) {
                document.body.removeChild(temporaryField);
                reject(error);
            }
        });
    }
}());
