document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('.tab');
    var content = document.getElementById('tab-content');
    if (!tabs.length || !content) return;

    function loadTab(tabName) {
        fetch('api.php?action=get_tab&tab=' + tabName)
            .then(function (res) { return res.text(); })
            .then(function (html) {
                content.innerHTML = '';
                var temp = document.createElement('div');
                temp.innerHTML = html;
                while (temp.firstChild) {
                    var node = temp.firstChild;
                    if (node.nodeType === 1 && node.tagName === 'SCRIPT') {
                        var script = document.createElement('script');
                        for (var i = 0; i < node.attributes.length; i++) {
                            script.setAttribute(node.attributes[i].name, node.attributes[i].value);
                        }
                        script.textContent = node.textContent;
                        node.parentNode.removeChild(node);
                        content.appendChild(script);
                    } else {
                        content.appendChild(node);
                    }
                }
            })
            .catch(function () {
                content.innerHTML = '<p style="color:red;">加载失败</p>';
            });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            loadTab(tab.dataset.tab);
        });
    });

    // 默认激活第一个可见 tab
    tabs[0].click();
});

document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('.tab');
    var content = document.getElementById('tab-content');
    if (!tabs.length || !content) return;

    function loadTab(tabName) {
        fetch('api.php?action=get_tab&tab=' + tabName)
            .then(function (res) { return res.text(); })
            .then(function (html) {
                content.innerHTML = '';
                var temp = document.createElement('div');
                temp.innerHTML = html;
                while (temp.firstChild) {
                    var node = temp.firstChild;
                    if (node.nodeType === 1 && node.tagName === 'SCRIPT') {
                        var script = document.createElement('script');
                        for (var i = 0; i < node.attributes.length; i++) {
                            script.setAttribute(node.attributes[i].name, node.attributes[i].value);
                        }
                        script.textContent = node.textContent;
                        node.parentNode.removeChild(node);
                        content.appendChild(script);
                    } else {
                        content.appendChild(node);
                    }
                }

                // 如果加载的是快捷操作 Tab，初始化小类下拉框
                if (tabName === 'quick' && typeof switchTypeCategory === 'function') {
                    // 默认大类为“惩罚”
                    document.getElementById('type-category').value = 'punish';
                    switchTypeCategory();
                }
            })
            .catch(function () {
                content.innerHTML = '<p style="color:red;">加载失败</p>';
            });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            loadTab(tab.dataset.tab);
        });
    });

    // 默认加载第一个可见 tab（可能是快捷操作，需初始化）
    if (tabs.length > 0) {
        tabs[0].click();
    }
});

function exportRecords() {
    const classId = document.getElementById('filter-class').value;
    let url = 'api.php?action=export_records';
    if (classId) url += '&class_id=' + classId;
    window.location.href = url;
}