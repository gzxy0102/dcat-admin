@if(Session::has('dcat-admin-toastr'))
    @php
        $toastr  = Session::get('dcat-admin-toastr');
        $type    = $toastr->get('type')[0] ?? 'success';
        $type    = in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info';
        $message = json_encode((string) ($toastr->get('message')[0] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '""';
        $options = admin_javascript_json($toastr->get('options', []));
    @endphp
    <script>$(function () { toastr.{!! $type !!}({!! $message !!}, null, {!! $options !!}); })</script>
@endif
