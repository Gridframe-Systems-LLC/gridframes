<?php

use App\Rules\ValidS3BucketName;

function validS3BucketNameRulePasses(string $bucket): bool
{
    $failed = false;

    (new ValidS3BucketName)->validate('bucket', $bucket, function () use (&$failed) {
        $failed = true;
    });

    return ! $failed;
}

it('accepts valid s3 bucket names', function (string $bucket) {
    expect(validS3BucketNameRulePasses($bucket))->toBeTrue("Expected accepted: {$bucket}");
})->with([
    'short' => ['abc'],
    'simple' => ['xlaunch-backups'],
    'legacy uppercase' => ['XlaunchBackups'],
    'dots' => ['xlaunch.backups'],
    'digits' => ['backup-123'],
    'max length' => [str_repeat('a', 63)],
]);

it('rejects invalid s3 bucket names and injection payloads', function (string $bucket) {
    expect(validS3BucketNameRulePasses($bucket))->toBeFalse("Expected rejected: {$bucket}");
})->with([
    'too short' => ['ab'],
    'too long' => [str_repeat('a', 64)],
    'underscore' => ['xlaunch_backups'],
    'leading hyphen' => ['-xlaunch-backups'],
    'trailing hyphen' => ['xlaunch-backups-'],
    'leading dot' => ['.xlaunch-backups'],
    'trailing dot' => ['xlaunch-backups.'],
    'consecutive dots' => ['xlaunch..backups'],
    'dot hyphen' => ['xlaunch.-backups'],
    'hyphen dot' => ['xlaunch-.backups'],
    'ipv4 address' => ['192.168.1.1'],
    'semicolon injection' => ['lab; id; #'],
    'command substitution' => ['lab$(id)'],
    'backticks' => ['lab`id`'],
    'pipe' => ['lab|id'],
    'ampersand' => ['lab&id'],
    'space' => ['lab bucket'],
    'newline' => ["lab\nid"],
]);
