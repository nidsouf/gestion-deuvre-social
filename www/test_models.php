<?php
// test_models.php - عرض النماذج المتاحة لمفتاح API
require_once 'config/ai_config.php';

$apiKey = AI_API_KEY;
$url = "https://generativelanguage.googleapis.com/v1/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "❌ فشل جلب النماذج (HTTP $httpCode): " . $response;
    exit;
}

$data = json_decode($response, true);
echo "<h2>📋 النماذج المتاحة لمفتاحك</h2>";
echo "<pre>";
print_r($data);
echo "</pre>";

echo "<hr>";
echo "<h3>✅ النماذج الموصى باستخدامها:</h3>";
echo "<ul>";
foreach ($data['models'] ?? [] as $model) {
    $name = $model['name'] ?? '';
    // استخراج اسم النموذج من المسار (models/gemini-xxx)
    $shortName = str_replace('models/', '', $name);
    if (strpos($shortName, 'gemini') !== false && strpos($shortName, 'generateContent') === false) {
        echo "<li><strong>$shortName</strong> - " . ($model['displayName'] ?? '') . "</li>";
    }
}
echo "</ul>";