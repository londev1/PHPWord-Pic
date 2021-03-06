<?php
/**
 * This file is part of PHPWord - A pure PHP library for reading and writing
 * word processing documents.
 *
 * PHPWord is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code. For the full list of
 * contributors, visit https://github.com/PHPOffice/PHPWord/contributors.
 *
 * @see         https://github.com/PHPOffice/PHPWord
 * @copyright   2010-2018 PHPWord contributors
 * @license     http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

namespace PhpOffice\PhpWord;

use PhpOffice\Common\Text;
use PhpOffice\PhpWord\Escaper\RegExp;
use PhpOffice\PhpWord\Escaper\Xml;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use PhpOffice\PhpWord\Exception\Exception;
use PhpOffice\PhpWord\Shared\ZipArchive;

class TemplateProcessor
{
    const MAXIMUM_REPLACEMENTS_DEFAULT = -1;

    protected $newPictures = array();
    protected $tempDocumentMainRels;
    protected $tempContentType;
    /**
     * ZipArchive object.
     *
     * @var mixed
     */
    protected $zipClass;

    /**
     * @var string Temporary document filename (with path)
     */
    protected $tempDocumentFilename;

    /**
     * Content of main document part (in XML format) of the temporary document
     *
     * @var string
     */
    protected $tempDocumentMainPart;

    /**
     * Content of headers (in XML format) of the temporary document
     *
     * @var string[]
     */
    protected $tempDocumentHeaders = array();

    /**
     * Content of footers (in XML format) of the temporary document
     *
     * @var string[]
     */
    protected $tempDocumentFooters = array();

    /**
     * @since 0.12.0 Throws CreateTemporaryFileException and CopyFileException instead of Exception
     *
     * @param string $documentTemplate The fully qualified template filename
     *
     * @throws \PhpOffice\PhpWord\Exception\CreateTemporaryFileException
     * @throws \PhpOffice\PhpWord\Exception\CopyFileException
     */
    public function __construct($documentTemplate)
    {
        // Temporary document filename initialization
        $this->tempDocumentFilename = tempnam(Settings::getTempDir(), 'PhpWord');
        if (false === $this->tempDocumentFilename) {
            throw new CreateTemporaryFileException();
        }

        // Template file cloning
        if (false === copy($documentTemplate, $this->tempDocumentFilename)) {
            throw new CopyFileException($documentTemplate, $this->tempDocumentFilename);
        }

        // Temporary document content extraction
        $this->zipClass = new ZipArchive();
        $this->zipClass->open($this->tempDocumentFilename);
        $index = 1;
        while (false !== $this->zipClass->locateName($this->getHeaderName($index))) {
            $this->tempDocumentHeaders[$index] = $this->fixBrokenMacros(
                $this->zipClass->getFromName($this->getHeaderName($index))
            );
            $index++;
        }
        $index = 1;
        while (false !== $this->zipClass->locateName($this->getFooterName($index))) {
            $this->tempDocumentFooters[$index] = $this->fixBrokenMacros(
                $this->zipClass->getFromName($this->getFooterName($index))
            );
            $index++;
        }
        $this->tempDocumentMainPart = $this->fixBrokenMacros($this->zipClass->getFromName($this->getMainPartName()));

        $this->tempDocumentMainRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        if ($this->zipClass->locateName('word/_rels/document.xml.rels')) $this->tempDocumentMainRels = $this->zipClass->getFromName('word/_rels/document.xml.rels');

        $this->tempContentType = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        if ($this->zipClass->locateName('[Content_Types].xml')!==false) $this->tempContentType = $this->zipClass->getFromName('[Content_Types].xml');


    }

    /**
     * @param string $xml
     * @param \XSLTProcessor $xsltProcessor
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     *
     * @return string
     */
    protected function transformSingleXml($xml, $xsltProcessor)
    {
        libxml_disable_entity_loader(true);
        $domDocument = new \DOMDocument();
        if (false === $domDocument->loadXML($xml)) {
            throw new Exception('Could not load the given XML document.');
        }

        $transformedXml = $xsltProcessor->transformToXml($domDocument);
        if (false === $transformedXml) {
            throw new Exception('Could not transform the given XML document.');
        }

        return $transformedXml;
    }

    /**
     * @param mixed $xml
     * @param \XSLTProcessor $xsltProcessor
     *
     * @return mixed
     */
    protected function transformXml($xml, $xsltProcessor)
    {
        if (is_array($xml)) {
            foreach ($xml as &$item) {
                $item = $this->transformSingleXml($item, $xsltProcessor);
            }
        } else {
            $xml = $this->transformSingleXml($xml, $xsltProcessor);
        }

        return $xml;
    }

    /**
     * Applies XSL style sheet to template's parts.
     *
     * Note: since the method doesn't make any guess on logic of the provided XSL style sheet,
     * make sure that output is correctly escaped. Otherwise you may get broken document.
     *
     * @param \DOMDocument $xslDomDocument
     * @param array $xslOptions
     * @param string $xslOptionsUri
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     */
    public function applyXslStyleSheet($xslDomDocument, $xslOptions = array(), $xslOptionsUri = '')
    {
        $xsltProcessor = new \XSLTProcessor();

        $xsltProcessor->importStylesheet($xslDomDocument);
        if (false === $xsltProcessor->setParameter($xslOptionsUri, $xslOptions)) {
            throw new Exception('Could not set values for the given XSL style sheet parameters.');
        }

        $this->tempDocumentHeaders = $this->transformXml($this->tempDocumentHeaders, $xsltProcessor);
        $this->tempDocumentMainPart = $this->transformXml($this->tempDocumentMainPart, $xsltProcessor);
        $this->tempDocumentFooters = $this->transformXml($this->tempDocumentFooters, $xsltProcessor);
    }

    /**
     * @param string $macro
     *
     * @return string
     */
    protected static function ensureMacroCompleted($macro)
    {
        if (substr($macro, 0, 2) !== '${' && substr($macro, -1) !== '}') {
            $macro = '${' . $macro . '}';
        }

        return $macro;
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    protected static function ensureUtf8Encoded($subject)
    {
        if (!Text::isUTF8($subject)) {
            $subject = utf8_encode($subject);
        }

        return $subject;
    }

    /**
     * @param mixed $search
     * @param mixed $replace
     * @param int $limit
     */
    public function setValue($search, $replace, $limit = self::MAXIMUM_REPLACEMENTS_DEFAULT)
    {
        if (is_array($search)) {
            foreach ($search as &$item) {
                $item = self::ensureMacroCompleted($item);
            }
        } else {
            $search = self::ensureMacroCompleted($search);
        }

        if (is_array($replace)) {
            foreach ($replace as &$item) {
                $item = self::ensureUtf8Encoded($item);
            }
        } else {
            $replace = self::ensureUtf8Encoded($replace);
        }

        if (Settings::isOutputEscapingEnabled()) {
            $xmlEscaper = new Xml();
            $replace = $xmlEscaper->escape($replace);
        }

        $this->tempDocumentHeaders = $this->setValueForPart($search, $replace, $this->tempDocumentHeaders, $limit);
        $this->tempDocumentMainPart = $this->setValueForPart($search, $replace, $this->tempDocumentMainPart, $limit);
        $this->tempDocumentFooters = $this->setValueForPart($search, $replace, $this->tempDocumentFooters, $limit);
    }


    public function removeVar($macro)
    {
        foreach ($this->tempDocumentHeaders as $index => $headerXML) {
            $this->tempDocumentHeaders[$index] = $this->removeVarForPart($this->tempDocumentHeaders[$index], $macro);
        }

        $this->tempDocumentMainPart = $this->removeVarForPart($this->tempDocumentMainPart, $macro);

        foreach ($this->tempDocumentFooters as $index => $headerXML) {
            $this->tempDocumentFooters[$index] = $this->removeVarForPart($this->tempDocumentFooters[$index], $macro);
        }
    }


    /**
     * Returns array of all variables in template.
     *
     * @return string[]
     */
    public function getVariables()
    {
        $variables = $this->getVariablesForPart($this->tempDocumentMainPart);

        foreach ($this->tempDocumentHeaders as $headerXML) {
            $variables = array_merge($variables, $this->getVariablesForPart($headerXML));
        }

        foreach ($this->tempDocumentFooters as $footerXML) {
            $variables = array_merge($variables, $this->getVariablesForPart($footerXML));
        }

        return array_unique($variables);
    }

    /**
     * Clone a table row in a template document.
     *
     * @param string $search
     * @param int $numberOfClones
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     */
    public function cloneRow($search, $numberOfClones)
    {
        if ('${' !== substr($search, 0, 2) && '}' !== substr($search, -1)) {
            $search = '${' . $search . '}';
        }

        $tagPos = strpos($this->tempDocumentMainPart, $search);
        if (!$tagPos) {
            throw new Exception('Can not clone row, template variable not found or variable contains markup.');
        }

        $rowStart = $this->findRowStart($tagPos);
        $rowEnd = $this->findRowEnd($tagPos);
        $xmlRow = $this->getSlice($rowStart, $rowEnd);

        // Check if there's a cell spanning multiple rows.
        if (preg_match('#<w:vMerge w:val="restart"/>#', $xmlRow)) {
            // $extraRowStart = $rowEnd;
            $extraRowEnd = $rowEnd;
            while (true) {
                $extraRowStart = $this->findRowStart($extraRowEnd + 1);
                $extraRowEnd = $this->findRowEnd($extraRowEnd + 1);

                // If extraRowEnd is lower then 7, there was no next row found.
                if ($extraRowEnd < 7) {
                    break;
                }

                // If tmpXmlRow doesn't contain continue, this row is no longer part of the spanned row.
                $tmpXmlRow = $this->getSlice($extraRowStart, $extraRowEnd);
                if (!preg_match('#<w:vMerge/>#', $tmpXmlRow) &&
                    !preg_match('#<w:vMerge w:val="continue" />#', $tmpXmlRow)) {
                    break;
                }
                // This row was a spanned row, update $rowEnd and search for the next row.
                $rowEnd = $extraRowEnd;
            }
            $xmlRow = $this->getSlice($rowStart, $rowEnd);
        }

        $result = $this->getSlice(0, $rowStart);
        for ($i = 1; $i <= $numberOfClones; $i++) {
            $result .= preg_replace('/\$\{(.*?)\}/', '\${\\1#' . $i . '}', $xmlRow);
        }
        $result .= $this->getSlice($rowEnd);

        $this->tempDocumentMainPart = $result;
    }

    /**
     * Clone a block.
     *
     * @param string $blockname
     * @param int $clones
     * @param bool $replace
     *
     * @return string|null
     */
    public function cloneBlock($blockname, $clones = 1, $replace = true)
    {
        $xmlBlock = null;
        preg_match(
            '/(<\?xml.*)(<w:p\b.*>\${' . $blockname . '}<\/w:.*?p>)(.*)(<w:p\b.*\${\/' . $blockname . '}<\/w:.*?p>)/is',
            $this->tempDocumentMainPart,
            $matches
        );

        if (isset($matches[3])) {
            $xmlBlock = $matches[3];
            $cloned = array();
            for ($i = 1; $i <= $clones; $i++) {
                $cloned[] = preg_replace('/\${(.*?)}/','${$1_'.$i.'}', $xmlBlock);
            }

            if ($replace) {
                $this->tempDocumentMainPart = str_replace(
                    $matches[2] . $matches[3] . $matches[4],
                    implode('', $cloned),
                    $this->tempDocumentMainPart
                );
            }
        }

        return $xmlBlock;
    }

    /**
     * Replace a block.
     *
     * @param string $blockname
     * @param string $replacement
     */
    public function replaceBlock($blockname, $replacement)
    {
        $matches = $this->findBlock($blockname);

        if (isset($matches[1]))
        {
            $this->tempDocumentMainPart = str_replace
                (
                    $matches[0],
                $replacement,
                $this->tempDocumentMainPart
            );
        }
    }

    /**
     * Delete a block of text.
     *
     * @param string $blockname
     */
    public function deleteBlock($blockname)
    {
        $this->replaceBlock($blockname, '');
    }

    /**
     * Dodaje obrazek do ZIPa, zwraca Unikalny ID, ktory pozniej uzywa sie do wstawiania obrazkow
     */
    public function addPicture($path)
    {
        $uid = 'lbp'.(count($this->newPictures)+1);
        $newpicture = array();
        $newpicture['uid'] = $uid;
        $newpicture['src_path'] = $path;
        $image = getimagesize($path); //GD wymaga
        $newpicture['sizex'] = $image[0];
        $newpicture['sizey'] = $image[1];
        $this->newPictures[$uid] = $newpicture;
        return $uid;
    }

    public function inlinePictureCode($uid)
    {
        $sizex = self::PxToEMU($this->newPictures[$uid]['sizex']);
        $sizey = self::PxToEMU($this->newPictures[$uid]['sizey']);
        return '<w:r><w:rPr><w:noProof/></w:rPr>'.
'<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$sizex.'" cy="'.$sizey.'"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'.
'<wp:docPr id="2" name="Obraz 2" descr="Next"/><wp:cNvGraphicFramePr>'.
'<a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'.
'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'.
'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="Picture '.$uid.'" descr="Next"/><pic:cNvPicPr>'.
'<a:picLocks noChangeAspect="1" noChangeArrowheads="1"/></pic:cNvPicPr></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$uid.'"><a:extLst>'.
'<a:ext uri="{28A0092B-C50C-407E-A947-70E740481C1C}"><a14:useLocalDpi xmlns:a14="http://schemas.microsoft.com/office/drawing/2010/main" val="0"/></a:ext></a:extLst>'.
'</a:blip><a:srcRect/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr bwMode="auto"><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$sizex.'" cy="'.$sizey.'"/></a:xfrm>'.
'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln><a:noFill/></a:ln></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>'.
'</w:r>';
    }

   /* MultilineStr */
    public function multiline($str)
    {
        $str = str_replace('<br>', '<w:br/>', $str);
        $str = str_replace('<br/>', '<w:br/>', $str);
        $str = str_replace("\r\n", "<w:br/>", $str);
        $str = str_replace("\n", "<w:br/>", $str);
        return $str;
    }

    public function html($str, $multiline=true)
    {
        //to nie dziala jeszcze dobrze :(
        $str = str_replace('<b>', '<w:r><w:rPr><w:b/></w:rPr>', $str);
        $str = str_replace('</b>', '</w:r>', $str);
        if ($multiline) return $this->multiline($str);
        return $str;
    }

    public function _br($sp=1)
    {
        $str = "";
        for($i=0;$i<$sp;$i++) $str .= "<w:br/>";
        return $str;
    }

    public function PxToEMU($size)
    {
        return $size*914400/96;
    }
    /**
     * Saves the result document.
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     *
     * @return string
     */
    public function save()
    {
        foreach ($this->tempDocumentHeaders as $index => $xml) {
            $this->zipClass->addFromString($this->getHeaderName($index), $xml);
        }

        $this->zipClass->addFromString($this->getMainPartName(), $this->tempDocumentMainPart);

        $pic_ext = array();
        foreach ($this->newPictures as $newpicture)
        {
           $ext = strtolower(pathinfo($newpicture['src_path'], PATHINFO_EXTENSION));
           $pic_ext[$ext] = 1;
           $filename = $newpicture['uid'].'.'.$ext;
           $picture = file_get_contents($newpicture['src_path']);
           $this->zipClass->addFromString('word/media/'.$filename, $picture);
           $uid = $newpicture['uid'];
           $this->tempDocumentMainRels = str_replace('</Relationships>', '<Relationship Id="'.$uid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$filename.'"/></Relationships>', $this->tempDocumentMainRels);
        }
        $tmpCt = $this->tempContentType;
        foreach ($pic_ext as $ext => $val)
        {
            if ($ext=="jpg") if (strpos($this->tempContentType, 'Default Extension="jpg"')===false) $this->tempContentType = str_replace('</Types>', '<Default Extension="jpg" ContentType="application/octet-stream"/></Types>', $this->tempContentType);
            if ($ext=="jpeg") if (strpos($this->tempContentType, 'Default Extension="jpeg"')===false) $this->tempContentType = str_replace('</Types>', '<Default Extension="jpeg" ContentType="application/octet-stream"/></Types>', $this->tempContentType);
            if ($ext=="png") if (strpos($this->tempContentType, 'Default Extension="png"')===false) $this->tempContentType = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $this->tempContentType);
        }
        if ($this->tempContentType!=$tmpCt) $this->zipClass->addFromString('[Content_Types].xml', $this->tempContentType);
        if (count($this->newPictures)>0) $this->zipClass->addFromString('word/_rels/document.xml.rels', $this->tempDocumentMainRels);
        foreach ($this->tempDocumentFooters as $index => $xml) {
            $this->zipClass->addFromString($this->getFooterName($index), $xml);
        }

        // Close zip file
        if (false === $this->zipClass->close()) {
            throw new Exception('Could not close zip file.');
        }

        return $this->tempDocumentFilename;
    }

    /**
     * Saves the result document to the user defined file.
     *
     * @since 0.8.0
     *
     * @param string $fileName
     */
    public function saveAs($fileName)
    {
        $tempFileName = $this->save();

        if (file_exists($fileName)) {
            unlink($fileName);
        }

        /*
         * Note: we do not use `rename` function here, because it loses file ownership data on Windows platform.
         * As a result, user cannot open the file directly getting "Access denied" message.
         *
         * @see https://github.com/PHPOffice/PHPWord/issues/532
         */
        copy($tempFileName, $fileName);
        unlink($tempFileName);
    }

    /**
     * Finds parts of broken macros and sticks them together.
     * Macros, while being edited, could be implicitly broken by some of the word processors.
     *
     * @param string $documentPart The document part in XML representation
     *
     * @return string
     */
    protected function fixBrokenMacros($documentPart)
    {
        $fixedDocumentPart = $documentPart;

        $fixedDocumentPart = preg_replace_callback(
            '|\$[^{]*\{[^}]*\}|U',
            function ($match) {
                return strip_tags($match[0]);
            },
            $fixedDocumentPart
        );

        return $fixedDocumentPart;
    }

    /**
     * Find and replace macros in the given XML section.
     *
     * @param mixed $search
     * @param mixed $replace
     * @param string $documentPartXML
     * @param int $limit
     *
     * @return string
     */
    protected function setValueForPart($search, $replace, $documentPartXML, $limit)
    {
        // Note: we can't use the same function for both cases here, because of performance considerations.
        if (self::MAXIMUM_REPLACEMENTS_DEFAULT === $limit) {
            return str_replace($search, $replace, $documentPartXML);
        }
        $regExpEscaper = new RegExp();

        return preg_replace($regExpEscaper->escape($search), $replace, $documentPartXML, $limit);
    }

    protected function removeVarForPart($documentPartXML, $search)
    {
        if (substr($search, 0, 2) !== '${' && substr($search, -1) !== '}') {
            $search = '${' . $search . '}';
        }


        $tagPos = strpos($documentPartXML, $search);
        if ($tagPos===false) return $documentPartXML;



        $rowStart = $this->findRowStart($tagPos, $documentPartXML);
        $rowEnd = $this->findRowEnd($rowStart, $documentPartXML);
        if ($rowEnd<$tagPos)
        {
           $rowStart = $this->findRowStart($tagPos, $documentPartXML, "w:p");
           $rowEnd = $this->findRowEnd($rowStart, $documentPartXML, "w:p");
           if ($rowEnd<$tagPos)
           {
               $rowStart = $this->findRowStart($tagPos, $documentPartXML, "w:r");
               $rowEnd = $this->findRowEnd($rowStart, $documentPartXML, "w:r");
               if ($rowEnd<$tagPos)
               {
                    return $documentPartXML;
               }
           }
        }


        $result = substr($documentPartXML, 0, $rowStart);
        $result .= substr($documentPartXML, $rowEnd);

        return $result;
    }

    /**
     * Find all variables in $documentPartXML.
     *
     * @param string $documentPartXML
     *
     * @return string[]
     */
    protected function getVariablesForPart($documentPartXML)
    {
        preg_match_all('/\$\{(.*?)}/i', $documentPartXML, $matches);

        return $matches[1];
    }

    /**
     * Get the name of the header file for $index.
     *
     * @param int $index
     *
     * @return string
     */
    protected function getHeaderName($index)
    {
        return sprintf('word/header%d.xml', $index);
    }

    /**
     * @return string
     */
    protected function getMainPartName()
    {
        return 'word/document.xml';
    }

    /**
     * Get the name of the footer file for $index.
     *
     * @param int $index
     *
     * @return string
     */
    protected function getFooterName($index)
    {
        return sprintf('word/footer%d.xml', $index);
    }

    /**
     * Find the start position of the nearest table row before $offset.
     *
     * @param int $offset
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     *
     * @return int
     */
    protected function findRowStart($offset, $part="", $tag="w:tr")
    {
        if ($part=="") $part = $this->tempDocumentMainPart;
        $rowStart = strrpos($part, '<'.$tag.' ', ((strlen($part) - $offset) * -1));

        if (!$rowStart) {
            $rowStart = strrpos($part, '<'.$tag.'>', ((strlen($part) - $offset) * -1));
        }
        if (!$rowStart) {
            throw new Exception('Can not find the start position of the row to clone.');
        }

        return $rowStart;
    }

    /**
     * Find the end position of the nearest table row after $offset.
     *
     * @param int $offset
     *
     * @return int
     */
    protected function findRowEnd($offset, $part="", $tag="w:tr")
    {
        if ($part=="") $part = $this->tempDocumentMainPart;
        $pos = strpos($part, '</'.$tag.'>', $offset);
        if ($pos===false) return 0;
        return strlen('</'.$tag.'>')+$pos;
    }

    /**
     * Get a slice of a string.
     *
     * @param int $startPosition
     * @param int $endPosition
     *
     * @return string
     */
    protected function getSlice($startPosition, $endPosition = 0)
    {
        if (!$endPosition) {
            $endPosition = strlen($this->tempDocumentMainPart);
        }

        return substr($this->tempDocumentMainPart, $startPosition, ($endPosition - $startPosition));
    }
    private function findBlock($blockname)
    {
        // Parse the XML
        $xml = new \SimpleXMLElement($this->tempDocumentMainPart);

        // Find the starting and ending tags
        $startNode = false; $endNode = false;
        foreach ($xml->xpath('//w:t') as $node)
        {
            if (strpos($node, '${'.$blockname.'}') !== false)
            {
                $startNode = $node;
                continue;
            }

            if (strpos($node, '${/'.$blockname.'}') !== false)
            {
                $endNode = $node;
                break;
            }
        }

        // Make sure we found the tags
        if ($startNode === false || $endNode === false)
        {
            return null;
        }

        // Find the parent <w:p> node for the start tag
        $node = $startNode; $startNode = null;
        while (is_null($startNode))
        {
            $node = $node->xpath('..')[0];

            if ($node->getName() == 'p')
            {
                $startNode = $node;
            }
        }

        // Find the parent <w:p> node for the end tag
        $node = $endNode; $endNode = null;
        while (is_null($endNode))
        {
            $node = $node->xpath('..')[0];

            if ($node->getName() == 'p')
            {
                $endNode = $node;
            }
        }

        /*
         * NOTE: Because SimpleXML reduces empty tags to "self-closing" tags.
         * We need to replace the original XML with the version of XML as
         * SimpleXML sees it. The following example should show the issue
         * we are facing.
         *
         * This is the XML that my document contained orginally.
         *
         * ```xml
         *  <w:p>
         *      <w:pPr>
         *          <w:pStyle w:val="TextBody"/>
         *          <w:rPr></w:rPr>
         *      </w:pPr>
         *      <w:r>
         *          <w:rPr></w:rPr>
         *          <w:t>${CLONEME}</w:t>
         *      </w:r>
         *  </w:p>
         * ```
         *
         * This is the XML that SimpleXML returns from asXml().
         *
         * ```xml
         *  <w:p>
         *      <w:pPr>
         *          <w:pStyle w:val="TextBody"/>
         *          <w:rPr/>
         *      </w:pPr>
         *      <w:r>
         *          <w:rPr/>
         *          <w:t>${CLONEME}</w:t>
         *      </w:r>
         *  </w:p>
         * ```
         */

        $this->tempDocumentMainPart = $xml->asXml();

        // Find the xml in between the tags
        $xmlBlock = null;
        preg_match
        (
            '/'.preg_quote($startNode->asXml(), '/').'(.*?)'.preg_quote($endNode->asXml(), '/').'/is',
            $this->tempDocumentMainPart,
            $matches
        );

        return $matches;
    }
}
