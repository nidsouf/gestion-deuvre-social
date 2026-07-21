<?php
// budget/history.php – إعادة توجيه إلى report.php
header("Location: report.php?" . $_SERVER['QUERY_STRING']);
exit;