<?php
file_put_contents('results.json', '[]');
file_put_contents('deleted_sessions.json', '[]');
file_put_contents('max_session.txt', '1');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Resetting...</title>
</head>
<body>
    <script>
        localStorage.removeItem('activeVaksinasiId');
        window.location.href = 'index.php';
    </script>
</body>
</html>
