<?php
if (isset($_GET['set'])) {
    $val = intval($_GET['set']);
    if ($val > 0) file_put_contents('max_session.txt', $val);
}
echo file_exists('max_session.txt') ? file_get_contents('max_session.txt') : 1;
