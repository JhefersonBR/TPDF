# Instalação
```shell
composer require jheferson-br/tpdf
```
## TPDF
A classe **TPDF** é uma extensão da [FPDF](http://www.fpdf.org/ "FPDF"), classe Para geração de arquivos em PDF em **PHP**.
E conta com alguns recursos a mais, como por exemplo o método **WriteHTML** que permite a escrita de texto com formatação via **HTML** extendendo assim o método **Write** da classe pai [FPDF](http://www.fpdf.org/ "FPDF").
Além disso com ela é possível criar cabeçalhos e rodapés nas paginas de forma simples
Veja um exemplo de uso:
```php
$unit = new SystemUnit($param['id']);
$pdf = new TPDF("P", 'mm', "A4");
$pdf->AliasNbPages();
$pdf->obj = $unit;

$pdf->header = function ($pd) {
	$pd->Image($pdf->obj->logo, 11, 12, 45, 15, '', '', true);
}

$pdf->footer = function ($pd) {
	$pd->SetFont('Arial', 'B', 14);
	$pd->Text(87,17,utf8_decode($pdf->obj->name));
}
```
