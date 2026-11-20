<?php
if (!defined('_PS_VERSION_'))
    exit;

class stats_Import_List extends Module
{
    private $html = '';

    public function __construct()
    {
        $this->name = 'stats_Import_List';
        $this->tab = 'analytics_stats';
        $this->version = '1.4';
        $this->author = 'dungnt';
        $this->need_instance = 0;
        parent::__construct();
        $this->displayName = $this->l('N80 - Danh sách lấy hàng');
        $this->description = 'Thống kê lượng hàng cần lấy theo đơn. Sau khi lấy thì phải addstock.';
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return parent::install() && $this->registerHook('AdminStatsModules');
    }

    public function hookAdminStatsModules()
    {
        $this->html = '';
        $currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));

        // Pagination: 10 products per page. Keep page input within a safe range.
        $perPage = 10;
        $page = (int)Tools::getValue('statsstock_page', 1);
        if ($page < 1)
            $page = 1;
        $offset = ($page - 1) * $perPage;

        // Keep the core PrestaShop stock helper, which derives product stock
        // from ps_stock_available and handles combinations/shop context.
        // Purchase price: latest supply-order unit price for this product.
        $sqlFrom = '
            FROM '._DB_PREFIX_.'product p
            '.Shop::addSqlAssociation('product', 'p').'
            INNER JOIN '._DB_PREFIX_.'product_lang pl
                ON (p.id_product = pl.id_product
                    AND pl.id_lang = '.(int)$this->context->language->id.'
                    '.Shop::addSqlRestrictionOnLang('pl').')
            '.Product::sqlStock('p', 0).'
            WHERE stock.quantity < 0';

        $sqlPrice = '(
            SELECT sod.unit_price_te
            FROM '._DB_PREFIX_.'supply_order_detail sod
            INNER JOIN '._DB_PREFIX_.'supply_order so
                ON so.id_supply_order = sod.id_supply_order
            WHERE sod.id_product = p.id_product
            ORDER BY so.date_add DESC, sod.id_supply_order_detail DESC
            LIMIT 1
        )';

        $sqlSelect = 'SELECT p.id_product, p.reference, pl.name,
            IFNULL('.$sqlPrice.', product_shop.wholesale_price) AS purchase_price,
            stock.quantity AS quantity
            '.$sqlFrom;

        // Calculate record count and total over ALL matching products,
        // independently of the current page.
        $totalRows = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) '.$sqlFrom
        );

        $totalValue = (float)Db::getInstance()->getValue(
            'SELECT SUM(quantity * IFNULL('.$sqlPrice.', product_shop.wholesale_price)) '.$sqlFrom
        );

        $totalPages = (int)ceil($totalRows / $perPage);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $products = Db::getInstance()->executeS(
            $sqlSelect.'
            ORDER BY p.id_product ASC
            LIMIT '.(int)$offset.', '.(int)$perPage
        );

        $ru = AdminController::$currentIndex.'&module='.$this->name
            .'&token='.Tools::getValue('token');

        $this->html .= '
            <script type="text/javascript">$(\'#calendar\').slideToggle();</script>
            <div class="panel-heading">'.$this->l('Danh sách hàng cần phải lấy theo đơn').'</div>';

        if (!$products) {
            $this->html .= '<p>'.$this->l('Ko có số liệu.').'</p>';
            return $this->html;
        }

        $this->html .= '
            <table class="table">
                <thead><tr>
                    <th>'.$this->l('ID product').'</th>
                    <th>'.$this->l('Product name').'</th>
                    <th>'.$this->l('Số còn thiếu').'</th>
                    <th>'.$this->l('Giá nhập').'</th>
                    <th>'.$this->l('Thành tiền').'</th>
                </tr></thead><tbody>';

        foreach ($products as $product) {
            $purchasePrice = (float)$product['purchase_price'];
            $quantity = (int)$product['quantity'];
            $stockValue = $quantity * $purchasePrice;

            $this->html .= '<tr>
                <td>'.(int)$product['id_product'].'</td>
                <td>'.Tools::safeOutput($product['name']).'</td>
                <td>'.$quantity.'</td>
                <td>'.Tools::displayPrice($purchasePrice, $currency).'</td>
                <td>'.Tools::displayPrice($stockValue, $currency).'</td>
            </tr>';
        }

        $this->html .= '</tbody>
            <tfoot><tr>
                <th colspan="4">'.$this->l('Tổng cộng').'</th>
                <th>'.Tools::displayPrice($totalValue, $currency).'</th>
            </tr></tfoot>
            </table>';

        // Use the AdminStatsModules URL and token already provided by PrestaShop.
        if ($totalPages > 1) {
            $this->html .= '<div class="pagination">';
            $this->html .= '<span>'.$this->l('Trang').' '.$page.' / '.$totalPages.'</span> ';

            if ($page > 1) {
                $this->html .= '<a class="btn btn-default" href="'.Tools::safeOutput($ru.'&statsstock_page='.($page - 1)).'">'
                    .$this->l('Trang trước').'</a> ';
            }
            if ($page < $totalPages) {
                $this->html .= '<a class="btn btn-default" href="'.Tools::safeOutput($ru.'&statsstock_page='.($page + 1)).'">'
                    .$this->l('Trang sau').'</a>';
            }
            $this->html .= '</div>';
        }

        return $this->html;
    }
}
