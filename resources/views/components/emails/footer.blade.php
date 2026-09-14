{{ Illuminate\Mail\Markdown::parse('---') }}

Thank you,<br>
{{ config('app.name') ?? 'Xlaunch' }}

{{ Illuminate\Mail\Markdown::parse('[Contact Support](https://northlatch.com/docs/contact)') }}
