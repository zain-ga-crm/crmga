<!doctype html>
<html>
<body>
    <h1>{{ $title }} — {{ $date->toDateString() }}</h1>
    <table>
        <tbody>
            @foreach ($counts as $label => $count)
                <tr>
                    <td>{{ str($label)->replace('_', ' ')->ucfirst() }}</td>
                    <td>{{ $count }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
