<?php
/**
 * includes/google_sheet_checker.php - فحص الطلبات الجديدة في Google Sheet
 */

if (!function_exists('checkNewGoogleSheetRequests')) {
    /**
     * يفحص Google Sheet ويُقارن مع sync_log
     * @param PDO $pdo
     * @param int $timeout
     * @return array ['count' => X, 'error' => null]
     */
    function checkNewGoogleSheetRequests($pdo, $timeout = 5) {
        // تحميل الرابط من الإعدادات
        require_once __DIR__ . '/../config/google_sheets.php';
        
        // Cache: نتجنب الفحص المتكرر (كل 3 دقائق)
        $cacheFile = sys_get_temp_dir() . '/gsheet_check_' . md5(GOOGLE_SHEET_CSV_URL) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 180) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached !== null) return $cached;
        }
        
        $result = ['count' => 0, 'error' => null, 'checked_at' => date('Y-m-d H:i:s')];
        
        try {
            // جلب CSV مع Cache Buster
            $url = GOOGLE_SHEET_CSV_URL . '&_=' . time();
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'user_agent' => 'PHP-Desktop-Checker/1.0',
                    'ignore_errors' => true,
                ]
            ]);
            
            $csv = @file_get_contents($url, false, $context);
            
            if ($csv === false || empty($csv)) {
                $result['error'] = 'تعذّر الاتصال بـ Google Sheets';
                return $result;
            }
            
            // تحليل CSV
            $lines = preg_split('/\r\n|\r|\n/', $csv);
            $lines = array_filter($lines, fn($l) => !empty(trim($l)));
            $lines = array_values($lines);
            
            if (count($lines) < 2) {
                $result['count'] = 0;
                file_put_contents($cacheFile, json_encode($result));
                return $result;
            }
            
            $header = str_getcsv(array_shift($lines));
            $header = array_map('trim', $header);
            
            // البحث عن عمود "حالة المزامنة"
            $syncColIndex = -1;
            foreach ($header as $i => $col) {
                if (stripos($col, 'مزامنة') !== false || stripos($col, 'sync') !== false) {
                    $syncColIndex = $i;
                    break;
                }
            }
            
            $newCount = 0;
            
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                if (count($row) < count($header)) {
                    $row = array_pad($row, count($header), '');
                }
                
                // إذا كان عمود المزامنة موجوداً وكان NO → طلب جديد
                if ($syncColIndex >= 0) {
                    $status = strtoupper(trim($row[$syncColIndex] ?? ''));
                    if ($status === 'NO' || $status === '') {
                        $newCount++;
                    }
                } else {
                    // إذا لم يوجد عمود، نحسب الكل
                    $newCount++;
                }
            }
            
            // طرح عدد الطلبات المُزامنة (من sync_log)
            $syncedCount = (int)$pdo->query("
                SELECT COUNT(*) FROM sync_log 
                WHERE source = 'google_sheets'
            ")->fetchColumn();
            
            // تقدير: الطلبات الجديدة = (كل الصفوف - 1 للرأس) - المُزامنة
            $totalRows = count($lines);
            $estimatedNew = max(0, $totalRows - $syncedCount);
            
            $result['count'] = $estimatedNew;
            
            // حفظ في Cache
            file_put_contents($cacheFile, json_encode($result));
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
}