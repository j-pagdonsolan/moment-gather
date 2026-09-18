<?php

return [
    // Upload requests allowed per minute per client IP.
    'rate_limit'  => (int) env('UPLOAD_RATE_LIMIT', 10),

    // Maximum number of files accepted in one request.
    'max_files'   => (int) env('UPLOAD_MAX_FILES', 20),

    // Maximum size per file in kilobytes (20 MB).
    'max_file_kb' => (int) env('UPLOAD_MAX_FILE_KB', 20480),
];
