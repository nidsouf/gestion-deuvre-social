<?php
// api/test_api.php
$url = 'http://127.0.0.1:53016/api/new_request.php';
$data = [
    'employee_name' => 'اختبار من PHP',
    'request_type' => 'grant',
    'grant_type' => 'نظارات',
    'amount' => 1500
];

$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data)
    ]
];
$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);
echo "النتيجة: " . $result;
?>