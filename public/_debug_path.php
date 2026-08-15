<?php
header('Content-Type: text/plain');
foreach (['REQUEST_URI','SCRIPT_NAME','PHP_SELF','DOCUMENT_ROOT'] as $k) {
  echo "$k=".($_SERVER[$k]??'')."\n";
}
