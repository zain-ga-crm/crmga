<!doctype html>
<html>
<body>
    <h1>Failed job alert</h1>
    <p>{{ $count }} job(s) failed in the last hour, at or above the configured threshold of {{ $threshold }}.</p>
    <p>Check the queue worker logs and the <code>failed_jobs</code> table for details.</p>
</body>
</html>
