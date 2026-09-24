{{ Illuminate\Mail\Markdown::parse('---') }}

Thank you,<br>
{{ config('app.name') ?? 'GridFrames' }}

{{ Illuminate\Mail\Markdown::parse('[Contact Support](https://projectxprotocol.dev/docs/contact)') }}
