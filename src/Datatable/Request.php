<?php

namespace Idsign\Vuetify\Datatable;

use Exception;
use Illuminate\Http\Request as IlluminateRequest;

/**
 * Class Request.
 *
 * @package Idsign\Vuetify\Datatable
 * @method input($key, $default = null)
 * @method has($key)
 * @method query($key, $default = null)
 * @author  Arjay Angeles <aqangeles@gmail.com>
 */
class Request
{
    /**
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * Request constructor.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function __construct(IlluminateRequest $request)
    {
        $this->request = $request;
    }

    /**
     * Proxy non existing method calls to request class.
     *
     * @param mixed $name
     * @param mixed $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->request, $name)) {
            return call_user_func_array([$this->request, $name], $arguments);
        }

        return null;
    }

    /**
     * Get attributes from request instance.
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->request->__get($name);
    }

    /**
     * Get all columns request input.
     *
     * @return array
     */
    public function columns()
    {
        return $this->estrapolateColumns();
    }

    public function page()
    {
        $sortJson = $this->estrapolateSort();
        return isset($sortJson['page']) ? $sortJson['page'] : 1;
    }

    public function pageSize()
    {
        $sortJson = $this->estrapolateSort();
        return isset($sortJson['rowsPerPage']) ? $sortJson['rowsPerPage'] : 1;
    }

    /**
     * Check if request uses legacy code
     *
     * @throws Exception
     */
    public function checkLegacyCode()
    {
        if (! $this->request->input('draw') && $this->request->input('sEcho')) {
            throw new Exception('DataTables legacy code is not supported! Please use DataTables 1.10++ coding convention.');
        } elseif (! $this->request->input('draw') && ! $this->request->input('columns')) {
            throw new Exception('Insufficient parameters');
        }
    }

    /**
     * Check if Datatables is searchable.
     *
     * @return bool
     */
    public function isSearchable()
    {
        $searchJson = $this->estrapolateSearch();
        if(!$searchJson){
            return false;
        }
        return  $searchJson['value'] != '' || $this->request->input('filter') != '';
    }

    private function estrapolateSearch(){
        return json_decode($this->request->input('search'), true) ? json_decode($this->request->input('search'), true) : [];
    }

    private function estrapolateColumns(){
        return json_decode($this->request->input('columns'), true) ? json_decode($this->request->input('columns'), true) : [];
    }

    private function estrapolateSort(){
        return json_decode($this->request->input('sort'), true) ? json_decode($this->request->input('sort'), true) : [];
    }

    /**
     * Check if Datatables must uses regular expressions
     *
     * @param integer $index
     * @return string
     */
    public function isRegex($index)
    {
        $columns = $this->estrapolateColumns();
        return isset($columns[$index]['search']['regex']) ? $columns[$index]['search']['regex'] : false;
        //return $this->request->input("columns.$index.search.regex") === 'true';
    }

    /**
     * Check if Datatables must uses regular expressions
     *
     * @param integer $index
     * @return string
     */
    public function isJson($index)
    {
        $columns = $this->estrapolateColumns();
        return isset($columns[$index]['json']) ? $columns[$index]['json'] : false;
    }

    /**
     * Get orderable columns
     *
     * @return array
     */
    public function orderableColumns()
    {
        if (! $this->isOrderable()) {
            return [];
        }

//        $orderable = [];
//        for ($i = 0, $c = count($this->request->input('order')); $i < $c; $i++) {
//            //$order_col = (int) $this->request->input("order.$i.column");
//            $order_col = (int) $this->request->input("order.$i.field");
//            $order_dir = $this->request->input("order.$i.dir");
//            if ($this->isColumnOrderable($order_col)) {
//                $orderable[] = ['column' => $order_col, 'direction' => $order_dir];
//            }
//        }

//        $orderable = [];
//        for ($i = 0, $c = count($this->request->input('sort')); $i < $c; $i++) {
//            //$order_col = (int) $this->request->input("order.$i.column");
//            $order_col = $this->request->input("sort.$i.field");
//            $order_dir = $this->request->input("sort.$i.dir");
//            $orderable[] = ['column' => $order_col, 'direction' => $order_dir];
//        }

        $orderableJson = $this->estrapolateSort();

        $columns = $this->estrapolateColumns();
        $column = collect($columns)->filter(function($el) use ($orderableJson){
            return $el['name'] == $orderableJson['sortBy'];
        })->first();


        $orderable[] = [
            'column' => $orderableJson['sortBy'],
            'json' => $column['json'] ?? false,
            'fallback' => $column['fallback'] ?? false,
            'direction' => $orderableJson['descending'] == true ? 'desc' : 'asc'
        ];

        return $orderable;
    }

    /**
     * Check if Datatables ordering is enabled.
     *
     * @return bool
     */
    public function isOrderable()
    {
        $sortJson = $this->estrapolateSort();
        //return $this->request->input('order') && count($this->request->input('order')) > 0;
        return $sortJson && $sortJson['sortBy'] != '';
    }

    /**
     * Check if a column is orderable.
     *
     * @param  integer $index
     * @return bool
     */
//    public function isColumnOrderable($index)
//    {
//        return $this->request->input("columns.$index.orderable") == 'true';
//    }

    /**
     * Get searchable column indexes
     *
     * @return array
     */
    public function searchableColumnIndex()
    {
        $searchable = [];
        for ($i = 0, $c = count($this->estrapolateColumns()); $i < $c; $i++) {
            if ($this->isColumnSearchable($i, false)) {
                $searchable[] = $i;
            }
        }

        return $searchable;
    }

    /**
     * Check if a column is searchable.
     *
     * @param integer $i
     * @param bool $column_search
     * @return bool
     */
    public function isColumnSearchable($i, $column_search = true)
    {
        $columnsJson = $this->estrapolateColumns();
        if ($column_search) {
            return isset($columnsJson[$i]['searchable']) && $columnsJson[$i]['searchable'] == true && $this->columnKeyword($i) != '';
        }

        return isset($columnsJson[$i]['searchable']) && $columnsJson[$i]['searchable'] == true;
    }

    /**
     * Get column's search value.
     *
     * @param integer $index
     * @return string
     */
    public function columnKeyword($index)
    {
        $columnJson = $this->estrapolateColumns();
        return isset($columnJson[$index]['search']['value']) ? $columnJson[$index]['search']['value'] : '';
    }

    /**
     * Get global search keyword
     *
     * @return string
     */
    public function keyword()
    {
        $searchJson = $this->estrapolateSearch();
        return isset($searchJson['value']) ? $searchJson['value'] : '';
    }

    /**
     * Get column identity from input or database.
     *
     * @param integer $i
     * @return string
     */
    public function columnName($i)
    {
        //$column = $this->request->input("columns.$i");
        $column = $this->estrapolateColumns()[$i];

        return isset($column['name']) && $column['name'] <> '' ? $column['name'] : $column['data'];
    }

    /**
     * Check if Datatables allow pagination.
     *
     * @return bool
     */
    public function isPaginationable()
    {
        //return ! is_null($this->request->input('start')) && ! is_null($this->request->input('length')) && $this->request->input('length') != -1;
        $sortJson = $this->estrapolateSort();
        return isset($sortJson['page']) && $sortJson['page'] != '' && isset($sortJson['rowsPerPage']) && $sortJson['rowsPerPage'] != '';
        //return ! is_null($this->request->input('page')) && ! is_null($this->request->input('pageSize'));
    }

    public function start(){
        return $this->page();
    }

    /**
     * Get filter array.
     *
     * @return array
     */
    public function filters(){
        return $this->request->input('filter');
    }
}
