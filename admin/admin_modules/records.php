<?php
// Admin view of patients' clinical records. Reuses the secretary module, which is
// already read-only (patient list + visit history modal + print); saving stays doctor-only.
include dirname(dirname(__DIR__)) . '/secretary/secretary_modules/records.php';
