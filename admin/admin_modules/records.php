<?php
// Admin view of the clinic log (completed visits across all doctors). Reuses the
// secretary module; it's read-only, saving records stays doctor-only.
include dirname(dirname(__DIR__)) . '/secretary/secretary_modules/records.php';
