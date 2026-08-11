{{-- DomPDF-embedded Poppins (OFL). Keep DejaVu as fallback for rare glyphs. --}}
@php
    $poppinsDir = str_replace('\\', '/', resource_path('fonts/poppins'));
@endphp
@font-face {
    font-family: 'Poppins';
    font-style: normal;
    font-weight: 400;
    src: url('{{ $poppinsDir }}/Poppins-Regular.ttf') format('truetype');
}
@font-face {
    font-family: 'Poppins';
    font-style: normal;
    font-weight: 500;
    src: url('{{ $poppinsDir }}/Poppins-Medium.ttf') format('truetype');
}
@font-face {
    font-family: 'Poppins';
    font-style: normal;
    font-weight: 600;
    src: url('{{ $poppinsDir }}/Poppins-SemiBold.ttf') format('truetype');
}
@font-face {
    font-family: 'Poppins';
    font-style: normal;
    font-weight: 700;
    src: url('{{ $poppinsDir }}/Poppins-Bold.ttf') format('truetype');
}
