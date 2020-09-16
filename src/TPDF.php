<?php
require_once  __DIR__.'/../../../../lib/adianti/core/AdiantiCoreLoader.php';
spl_autoload_register(array('Adianti\Core\AdiantiCoreLoader', 'autoload'));

function hex2dec($couleur = "#000000") {
    $R = substr($couleur, 1, 2);
    $rouge = hexdec($R);
    $V = substr($couleur, 3, 2);
    $vert = hexdec($V);
    $B = substr($couleur, 5, 2);
    $bleu = hexdec($B);
    $tbl_couleur = array();
    $tbl_couleur['R'] = $rouge;
    $tbl_couleur['V'] = $vert;
    $tbl_couleur['B'] = $bleu;
    return $tbl_couleur;
}

//conversion pixel -> millimeter at 72 dpi
function px2mm($px) {
    return $px * 25.4 / 72;
}

function txtentities($html) {
    $trans = get_html_translation_table(HTML_ENTITIES);
    $trans = array_flip($trans);
    return strtr($html, $trans);
}

////////////////////////////////////
class TPDF extends FPDF {

//Private properties
    var $tmpFiles = array();
    var $fontlist = array();
    public $cabecalho;
    public $rodape;
    public $obj;

    /*     * *****************************************************************************
     *                                                                              *
     *                               Public methods                                 *
     *                                                                              *
     * ***************************************************************************** */

    function Image($file, $x, $y, $w = 0, $h = 0, $type = '', $link = '', $isMask = false, $maskImg = 0) {
        //Put an image on the page
        if (!isset($this->images[$file])) {
            //First use of image,  get info
            if ($type == '') {
                $pos = strrpos($file, '.');
                if (!$pos)
                    $this->Error('Image file has no extension and no type was specified: ' . $file);
                $type = substr($file, $pos + 1);
            }
            $type = strtolower($type);
            //$mqr=get_magic_quotes_runtime();
            //set_magic_quotes_runtime(0);
            if ($type == 'jpg' || $type == 'jpeg')
                $info = $this->_parsejpg($file);
            elseif ($type == 'png') {
                $info = $this->_parsepng($file);
                if ($info == 'alpha')
                    return $this->ImagePngWithAlpha($file, $x, $y, $w, $h, $link);
            }
            else {
                //Allow for additional formats
                $mtd = '_parse' . $type;
                if (!method_exists($this, $mtd))
                    $this->Error('Unsupported image type: ' . $type);
                $info = $this->$mtd($file);
            }
            //set_magic_quotes_runtime($mqr);

            if ($isMask) {
                $info['cs'] = "DeviceGray"; // try to force grayscale (instead of indexed)
            }
            $info['i'] = count($this->images) + 1;
            if ($maskImg > 0)
                $info['masked'] = $maskImg;###
            $this->images[$file] = $info;
        } else
        $info = $this->images[$file];
        //Automatic width and height calculation if needed
        if ($w == 0 && $h == 0) {
            //Put image at 72 dpi
            $w = $info['w'] / $this->k;
            $h = $info['h'] / $this->k;
        }
        if ($w == 0)
            $w = $h * $info['w'] / $info['h'];
        if ($h == 0)
            $h = $w * $info['h'] / $info['w'];

        // embed hidden,  ouside the canvas
        if ((float) FPDF_VERSION >= 1.7) {
            if ($isMask)
                $x = ($this->CurOrientation == 'P' ? $this->CurPageSize[0] : $this->CurPageSize[1]) + 10;
        }else {
            if ($isMask)
                $x = ($this->CurOrientation == 'P' ? $this->CurPageFormat[0] : $this->CurPageFormat[1]) + 10;
        }

        $this->_out(sprintf('q %.2f 0 0 %.2f %.2f %.2f cm /I%d Do Q', $w * $this->k, $h * $this->k, $x * $this->k, ($this->h - ($y + $h)) * $this->k, $info['i']));
        if ($link)
            $this->Link($x, $y, $w, $h, $link);

        return $info['i'];
    }
    function MemImage($data, $x=null, $y=null, $w=0, $h=0, $link='')
    {
        // Display the image contained in $data
        $v = 'img'.md5($data);
        $GLOBALS[$v] = $data;
        $a = getimagesize('var://'.$v);
        if(!$a)
            $this->Error('Invalid image data');
        $type = substr(strstr($a['mime'],'/'),1);
        $this->Image('var://'.$v, $x, $y, $w, $h, $type, $link);
        unset($GLOBALS[$v]);
    }

    function GDImage($im, $x=null, $y=null, $w=0, $h=0, $link='')
    {
        // Display the GD image associated with $im
        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        $this->MemImage($data, $x, $y, $w, $h, $link);
    }
    public function Header() {
        if (!empty($this->cabecalho)) {
            call_user_func($this->cabecalho, $this);
        }
    }

    function Footer() {
        if (!empty($this->rodape)) {
            call_user_func($this->rodape, $this);
        }
    }

    function WriteHTML($html) {
        //HTML parser
        $html = strip_tags($html, "<b><u><i><a><img><div><p><br><strong><em><font><tr><blockquote>"); //supprime tous les tags sauf ceux reconnus
        $html = str_replace("\n", ' ', $html); //remplace retour à la ligne par un espace
        $a = preg_split('/<(.*)>/U', $html, -1, PREG_SPLIT_DELIM_CAPTURE); //éclate la chaîne avec les balises
        foreach ($a as $i => $e) {
            if ($i % 2 == 0) {
                //Text
                if ($this->HREF)
                    $this->PutLink($this->HREF, $e);
                else
                    $this->Write(5, stripslashes(txtentities($e)));
            }
            else {
                //Tag
                if ($e[0] == '/')
                    $this->CloseTag(strtoupper(substr($e, 1)));
                else {
                    //Extract attributes
                    $a2 = explode(' ', $e);
                    $tag = strtoupper(array_shift($a2));
                    $attr = array();
                    foreach ($a2 as $v) {
                        if (preg_match('/([^=]*)=["\']?([^"\']*)/', $v, $a3))
                            $attr[strtoupper($a3[1])] = $a3[2];
                    }
                    $this->OpenTag($tag, $attr);
                }
            }
        }
    }

    function OpenTag($tag, $attr) {
        //Opening tag
        switch ($tag) {
            case 'STRONG':
            $this->SetStyle('B', true);
            break;
            case 'EM':
            $this->SetStyle('I', true);
            break;
            case 'B':
            case 'I':
            case 'U':
            $this->SetStyle($tag, true);
            break;
            case 'A':
            $this->HREF = $attr['HREF'];
            break;
            case 'IMG':
            if (isset($attr['SRC']) && (isset($attr['WIDTH']) || isset($attr['HEIGHT']))) {
                if (!isset($attr['WIDTH']))
                    $attr['WIDTH'] = 0;
                if (!isset($attr['HEIGHT']))
                    $attr['HEIGHT'] = 0;
                $this->Image($attr['SRC'], $this->GetX(), $this->GetY(), px2mm($attr['WIDTH']), px2mm($attr['HEIGHT']));
            }
            break;
            case 'TR':
            case 'BLOCKQUOTE':
            case 'BR':
            $this->Ln(5);
            break;
            case 'DIV':
            $this->Ln(3);
            break;
            case 'P':
            $this->Ln(10);
            break;
            case 'FONT':
            if (isset($attr['COLOR']) && $attr['COLOR'] != '') {
                $coul = hex2dec($attr['COLOR']);
                $this->SetTextColor($coul['R'], $coul['V'], $coul['B']);
                $this->issetcolor = true;
            }
            if (isset($attr['FACE']) && in_array(strtolower($attr['FACE']), $this->fontlist)) {
                $this->SetFont(strtolower($attr['FACE']));
                $this->issetfont = true;
            }
            break;
        }
    }

    function CloseTag($tag) {
        //Closing tag
        if ($tag == 'STRONG')
            $tag = 'B';
        if ($tag == 'EM')
            $tag = 'I';
        if ($tag == 'B' || $tag == 'I' || $tag == 'U')
            $this->SetStyle($tag, false);
        if ($tag == 'A')
            $this->HREF = '';
        if ($tag == 'FONT') {
            if ($this->issetcolor == true) {
                $this->SetTextColor(0);
            }
            if ($this->issetfont) {
                $this->SetFont('arial');
                $this->issetfont = false;
            }
        }
    }

    function SetStyle($tag, $enable) {
        //Modify style and select corresponding font
        $this->$tag += ($enable ? 1 : -1);
        $style = '';
        foreach (array('B', 'I', 'U') as $s) {
            if ($this->$s > 0)
                $style .= $s;
        }
        $this->SetFont('', $style);
    }

    function PutLink($URL, $txt) {
        //Put a hyperlink
        $this->SetTextColor(0, 0, 255);
        $this->SetStyle('U', true);
        $this->Write(5, $txt, $URL);
        $this->SetStyle('U', false);
        $this->SetTextColor(0);
    }

// needs GD 2.x extension
// pixel-wise operation,  not very fast
    function ImagePngWithAlpha($file, $x, $y, $w = 0, $h = 0, $link = '') {
        $tmp_alpha = tempnam('.', 'mska');
        $this->tmpFiles[] = $tmp_alpha;
        $tmp_plain = tempnam('.', 'mskp');
        $this->tmpFiles[] = $tmp_plain;

        list($wpx, $hpx) = getimagesize($file);
        $img = imagecreatefrompng($file);
        $alpha_img = imagecreate($wpx, $hpx);

        // generate gray scale pallete
        for ($c = 0; $c < 256; $c++)
            ImageColorAllocate($alpha_img, $c, $c, $c);

        // extract alpha channel
        $xpx = 0;
        while ($xpx < $wpx) {
            $ypx = 0;
            while ($ypx < $hpx) {
                $color_index = imagecolorat($img, $xpx, $ypx);
                $alpha = 255 - ($color_index >> 24) * 255 / 127; // GD alpha component: 7 bit only,  0..127!
                imagesetpixel($alpha_img, $xpx, $ypx, $alpha);
                ++$ypx;
            }
            ++$xpx;
        }

        imagepng($alpha_img, $tmp_alpha);
        imagedestroy($alpha_img);

        // extract image without alpha channel
        $plain_img = imagecreatetruecolor($wpx, $hpx);
        imagecopy($plain_img, $img, 0, 0, 0, 0, $wpx, $hpx);
        imagepng($plain_img, $tmp_plain);
        imagedestroy($plain_img);

        //first embed mask image (w,  h,  x,  will be ignored)
        $maskImg = $this->Image($tmp_alpha, 0, 0, 0, 0, 'PNG', '', true);

        //embed image,  masked with previously embedded mask
        $this->Image($tmp_plain, $x, $y, $w, $h, 'PNG', $link, false, $maskImg);
    }

    function Close() {
        parent::Close();
        // clean up tmp files
        foreach ($this->tmpFiles as $tmp)
            @unlink($tmp);
    }

    /*     * *****************************************************************************
     *                                                                              *
     *                               Private methods                                *
     *                                                                              *
     * ***************************************************************************** */

    function _putimages() {
        $filter = ($this->compress) ? '/Filter /FlateDecode ' : '';
        reset($this->images);
        while (list($file, $info) = each($this->images)) {
            $this->_newobj();
            $this->images[$file]['n'] = $this->n;
            $this->_out('<</Type /XObject');
            $this->_out('/Subtype /Image');
            $this->_out('/Width ' . $info['w']);
            $this->_out('/Height ' . $info['h']);

            if (isset($info["masked"]))
                $this->_out('/SMask ' . ($this->n - 1) . ' 0 R');###

            if ($info['cs'] == 'Indexed')
                $this->_out('/ColorSpace [/Indexed /DeviceRGB ' . (strlen($info['pal']) / 3 - 1) . ' ' . ($this->n + 1) . ' 0 R]');
            else {
                $this->_out('/ColorSpace /' . $info['cs']);
                if ($info['cs'] == 'DeviceCMYK')
                    $this->_out('/Decode [1 0 1 0 1 0 1 0]');
            }
            $this->_out('/BitsPerComponent ' . $info['bpc']);
            if (isset($info['f']))
                $this->_out('/Filter /' . $info['f']);
            if (isset($info['parms']))
                $this->_out($info['parms']);
            if (isset($info['trns']) && is_array($info['trns'])) {
                $trns = '';
                for ($i = 0; $i < count($info['trns']); $i++)
                    $trns .= $info['trns'][$i] . ' ' . $info['trns'][$i] . ' ';
                $this->_out('/Mask [' . $trns . ']');
            }
            $this->_out('/Length ' . strlen($info['data']) . '>>');
            $this->_putstream($info['data']);
            unset($this->images[$file]['data']);
            $this->_out('endobj');
            //Palette
            if ($info['cs'] == 'Indexed') {
                $this->_newobj();
                $pal = ($this->compress) ? gzcompress($info['pal']) : $info['pal'];
                $this->_out('<<' . $filter . '/Length ' . strlen($pal) . '>>');
                $this->_putstream($pal);
                $this->_out('endobj');
            }
        }
    }

// this method overwriing the original version is only needed to make the Image method support PNGs with alpha channels.
// if you only use the ImagePngWithAlpha method for such PNGs,  you can remove it from this script.
    function _parsepng($file) {
        //Extract info from a PNG file
        $f = fopen($file, 'rb');
        if (!$f)
            $this->Error('Can\'t open image file: ' . $file);
        //Check signature
        if (fread($f, 8) != chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10))
            $this->Error('Not a PNG file: ' . $file);
        //Read header chunk
        fread($f, 4);
        if (fread($f, 4) != 'IHDR')
            $this->Error('Incorrect PNG file: ' . $file);
        $w = $this->_readint($f);
        $h = $this->_readint($f);
        $bpc = ord(fread($f, 1));
        if ($bpc > 8)
            $this->Error('16-bit depth not supported: ' . $file);
        $ct = ord(fread($f, 1));
        if ($ct == 0)
            $colspace = 'DeviceGray';
        elseif ($ct == 2)
            $colspace = 'DeviceRGB';
        elseif ($ct == 3)
            $colspace = 'Indexed';
        else {
            fclose($f);      // the only changes are 
            return 'alpha';  // made in those 2 lines
        }
        if (ord(fread($f, 1)) != 0)
            $this->Error('Unknown compression method: ' . $file);
        if (ord(fread($f, 1)) != 0)
            $this->Error('Unknown filter method: ' . $file);
        if (ord(fread($f, 1)) != 0)
            $this->Error('Interlacing not supported: ' . $file);
        fread($f, 4);
        $parms = '/DecodeParms <</Predictor 15 /Colors ' . ($ct == 2 ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w . '>>';
        //Scan chunks looking for palette,  transparency and image data
        $pal = '';
        $trns = '';
        $data = '';
        do {
            $n = $this->_readint($f);
            $type = fread($f, 4);
            if ($type == 'PLTE') {
                //Read palette
                $pal = fread($f, $n);
                fread($f, 4);
            } elseif ($type == 'tRNS') {
                //Read transparency info
                $t = fread($f, $n);
                if ($ct == 0)
                    $trns = array(ord(substr($t, 1, 1)));
                elseif ($ct == 2)
                    $trns = array(ord(substr($t, 1, 1)), ord(substr($t, 3, 1)), ord(substr($t, 5, 1)));
                else {
                    $pos = strpos($t, chr(0));
                    if ($pos !== false)
                        $trns = array($pos);
                }
                fread($f, 4);
            }
            elseif ($type == 'IDAT') {
                //Read image data block
                $data .= fread($f, $n);
                fread($f, 4);
            } elseif ($type == 'IEND')
            break;
            else
                fread($f, $n + 4);
        }
        while ($n);
        if ($colspace == 'Indexed' && empty($pal))
            $this->Error('Missing palette in ' . $file);
        fclose($f);
        return array('w' => $w, 'h' => $h, 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'FlateDecode', 'parms' => $parms, 'pal' => $pal, 'trns' => $trns, 'data' => $data);
    }

}
