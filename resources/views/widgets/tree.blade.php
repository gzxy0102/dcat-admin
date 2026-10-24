<div  {!! $attributes !!}><div class="da-tree"></div></div>

<script require="@jstree">
    var opts = {!! admin_javascript_json($options) !!}, $tree = $('#{{$id}}').find('.da-tree');

    opts.core = opts.core || {};
    if (opts.core.force_text === undefined) {
        // 节点 text 按纯文本渲染，防止 XSS
        opts.core.force_text = true;
    }
    opts.core.data = {!! admin_javascript_json($nodes) !!};

    $tree.on("loaded.jstree", function () {
        $tree.jstree('open_all');
    }).jstree(opts);
</script>