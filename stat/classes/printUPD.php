<?php


class printUPD
{

    private static $pageSize = 670;
    private static $defaultRowSize = 30;

    public static function getInfo($positions, $rowSize = null)
    {
        if ($rowSize === null) {
            $rowSize = self::$defaultRowSize;
        }
        $changeSize = get_param_integer('is_pdf', 0);
        if (!$changeSize) {
            self::changePageSize(720);
        }

        $page = self::constructPage($positions, $rowSize);

        $size = 0;
        $pageNum = 1;
        $newPageLineIndex = array();
        foreach ($page as $k => $pobj) {
            if (defined("print_debug")) {
                echo "\n--------------------";
                echo "\n" . $pobj["obj"];
                echo "\nsize: " . $size . " (rowSize: " . $rowSize . ")";
                echo "\nsize+p[size]: " . ($size + $pobj["size"]);
                echo "\npage: " . $pageNum . ", pageSize: " . ($pageNum * self::$pageSize);
            }

            if ($pobj["obj"] == "line") {
                $newPageLineIndex[$k - 1] = false;
            }

            $isNewPage = ($size + $pobj["size"] >= $pageNum * self::$pageSize);

            if ($isNewPage) {
                // if footer or last product line on 2 pages
                if ($pobj["obj"] == "footer" || ($pobj["obj"] == "line" && $pobj["is_last"] && $rowSize < 100)) {
                    return self::getInfo($positions, $rowSize + 1);
                } else {
                    $newPageLineIndex[$k - 1] = $pageNum * self::$pageSize - $size;
                    $size = $pageNum * self::$pageSize;
                }
                $pageNum++;
            }
            $size += $pobj["size"];
        }
        return array("row_size" => $rowSize, "pages" => $pageNum, "newPageLineIndex" => $newPageLineIndex);
    }

    private static function constructPage($positions, $rowSize)
    {
        $page = array();
        $page[] = array("obj" => "header", "size" => self::$defaultRowSize * 11);

        for ($i = 1; $i <= $positions; $i++) {
            $page[] = array("obj" => "line", "size" => $rowSize, "is_last" => false);
        }

        if ($positions)
            $page[count($page) - 1]["is_last"] = true;

        $page[] = array("obj" => "footer", "size" => self::$defaultRowSize * 10);

        return $page;
    }

    private static function changePageSize($size)
    {
        self::$pageSize = $size;
    }
}

