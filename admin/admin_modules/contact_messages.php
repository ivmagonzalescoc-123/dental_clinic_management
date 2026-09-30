<?php
// Admin view of Contact Us inquiries. Reuses the secretary module, which switches to
// view-only for non-secretary roles (the API also rejects admin changes).
include dirname(dirname(__DIR__)) . '/secretary/secretary_modules/contact_messages.php';
