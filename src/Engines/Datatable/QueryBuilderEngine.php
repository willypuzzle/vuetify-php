<?php

namespace Idsign\Vuetify\Engines\Datatable;

use Closure;
use Idsign\Vuetify\Datatable\Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Idsign\Helpers\Facades\General\Environment;
use Idsign\Vuetify\Datatable\Helper;
use Idsign\Vuetify\Datatable\Request;

/**
 * Class QueryBuilderEngine.
 *
 * @package Idsign\Kendo\Engines\Grid
 * @author  Arjay Angeles <aqangeles@gmail.com>
 * @author  Domenico Rizzo <domenico.rizzo@gmail.com>
 */
class QueryBuilderEngine extends BaseEngine
{
    /**
     * @param \Illuminate\Database\Query\Builder $builder
     * @param \Idsign\Vuetify\Datatable\Request $request
     */
    public function __construct(Builder $builder, Request $request)
    {
        $this->query = $builder;
        $this->init($request, $builder);
    }

    /**
     * Initialize attributes.
     *
     * @param  \Idsign\Vuetify\Datatable\Request $request
     * @param  \Illuminate\Database\Query\Builder $builder
     * @param  string $type
     */
    protected function init($request, $builder, $type = 'builder')
    {
        $this->request    = $request;
        $this->query_type = $type;
        $this->columns    = $builder->columns;
        $this->connection = $builder->getConnection();
        $this->prefix     = $this->connection->getTablePrefix();
        $this->database   = $this->connection->getDriverName();
        if ($this->isDebugging()) {
            $this->connection->enableQueryLog();
        }
    }

    /**
     * Set auto filter off and run your own filter.
     * Overrides global search
     *
     * @param \Closure $callback
     * @param bool $globalSearch
     * @return $this
     */
    public function filter(Closure $callback, $globalSearch = false)
    {
        $this->overrideGlobalSearch($callback, $this->query, $globalSearch);

        return $this;
    }

    /**
     * Organizes works
     *
     * @param bool $mDataSupport
     * @param bool $orderFirst
     * @param bool $customize
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function make($mDataSupport = false, $orderFirst = false, $customize = false)
    {
        return parent::make($mDataSupport, $orderFirst, $customize);
    }

    /**
     * Count total items.
     *
     * @return integer
     */
    public function totalCount()
    {
        return $this->totalRecords ? $this->totalRecords : $this->count();
    }

    /**
     * Counts current query.
     *
     * @return int
     */
    public function count()
    {
        $myQuery = clone $this->query;
        // if its a normal query ( no union, having and distinct word )
        // replace the select with static text to improve performance
        if (! Str::contains(Str::lower($myQuery->toSql()), ['union', 'having', 'distinct', 'order by', 'group by'])) {
            $row_count = $this->wrap('row_count');
            $myQuery->select($this->connection->raw("'1' as {$row_count}"));
        }

        return $this->connection->table($this->connection->raw('(' . $myQuery->toSql() . ') count_row_table'))
                                ->setBindings($myQuery->getBindings())->count();
    }

    /**
     * Wrap column with DB grammar.
     *
     * @param string $column
     * @return string
     */
    protected function wrap($column)
    {
        return $this->connection->getQueryGrammar()->wrap($column);
    }

    /**
     * Perform global search.
     *
     * @return void
     */
    public function filtering()
    {
        $keyword = $this->request->keyword();

        //$filterArray = $this->request->filters();

        //$this->performMultiColumnFilter($filterArray);

        if ($this->isSmartSearch()) {
            $this->smartGlobalSearch($keyword);

            return;
        }

        $this->globalSearch($keyword);
    }

    /**
     * Perform multi-term search by splitting keyword into
     * individual words and searches for each of them.
     *
     * @param string $keyword
     */
    private function smartGlobalSearch($keyword)
    {
        $keywords = array_filter(explode(' ', $keyword));

        foreach ($keywords as $keyword) {
            $this->globalSearch($keyword);
        }
    }

    /**
     * Perform global search for the given keyword.
     *
     * @param string $keyword
     */
    private function globalSearch($keyword)
    {
        $this->query->where(
            function ($query) use ($keyword) {
                $queryBuilder = $this->getQueryBuilder($query);

                foreach ($this->request->searchableColumnIndex() as $index) {
                    $columnName = $this->getColumnName($index, false, true);
                    if ($this->isBlacklisted($columnName) && ! $this->hasCustomFilter($columnName)) {
                        continue;
                    }

                    // check if custom column filtering is applied
                    if ($this->hasCustomFilter($columnName)) {
                        $columnDef = $this->columnDef['filter'][$columnName];
                        // check if global search should be applied for the specific column
                        $applyGlobalSearch = count($columnDef['parameters']) == 0 || end($columnDef['parameters']) !== false;
                        if (! $applyGlobalSearch) {
                            continue;
                        }

                        if ($columnDef['method'] instanceof Closure) {
                            $whereQuery = $queryBuilder->newQuery();
                            call_user_func_array($columnDef['method'], [$whereQuery, $keyword]);
                            $queryBuilder->addNestedWhereQuery($whereQuery, 'or');
                        } else {
                            $this->compileColumnQuery(
                                $queryBuilder,
                                Helper::getOrMethod($columnDef['method']),
                                $columnDef['parameters'],
                                $columnName,
                                $keyword
                            );
                        }
                    } else {
                        if (count(explode('.', $columnName)) > 1) {
                            $eagerLoads     = $this->getEagerLoads();
                            $parts          = explode('.', $columnName);
                            $relationColumn = array_pop($parts);
                            $relation       = implode('.', $parts);
                            if (in_array($relation, $eagerLoads)) {
                                $this->compileRelationSearch(
                                    $queryBuilder,
                                    $relation,
                                    $relationColumn,
                                    $keyword,
                                    $index
                                );
                            } else {
                                $this->compileQuerySearch($queryBuilder, $columnName, $keyword, 'or', $index);
                            }
                        } else {
                            $this->compileQuerySearch($queryBuilder, $columnName, $keyword, 'or', $index);
                        }
                    }

                    $this->isFilterApplied = true;
                }
            }
        );
    }

    /**
     * Perform multi column filter.
     *
     * @param string $keyword
     */
    public function performMultiColumnFilter(array $filters)
    {
        $this->query->where(
            function ($query) use ($filters) {
                $queryBuilder = $this->getQueryBuilder($query);

                if(!isset($filters['logic']) || !isset($filters['filters']))
                {
                    return;
                }

                $mainOperator = $this->getMainLogicFunctionName($filters['logic']);
                $mainArray = $filters['filters'];

                foreach ($mainArray as $firstLevelFilter)
                {
                    $queryBuilder->$mainOperator(function ($query) use ($firstLevelFilter){
                        if(isset($firstLevelFilter['logic']) && isset($firstLevelFilter['filters'])){
                            $localOperator = $this->getMainLogicFunctionName($firstLevelFilter['logic']);
                            $localArray = $firstLevelFilter['filters'];
                            foreach ($localArray as $secondLevelFilter){
                                $query->$localOperator(function ($query) use ($secondLevelFilter){
                                    $this->buildMultiColumnFilterLine($query, $secondLevelFilter);
                                });
                            }
                        }else{
                            $this->buildMultiColumnFilterLine($query, $firstLevelFilter);
                        }
                    });

                }
            }
        );
    }

    /**
     * Return main logic function name (it's used in QueryBuilder).
     *
     * @param  string $logic
     * @return string
     */
    private function getMainLogicFunctionName($logic)
    {
        switch (trim($logic)){
            case 'and':
                return 'where';
            case 'or':
                return 'orWhere';
            default:
                throw new \Exception("{$logic} is a unknown operator");
        }
    }

    /**
     * Builds a single line in multi column filter.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param array $queryData
     * @return void
     */
    private function buildMultiColumnFilterLine(&$query, array $queryData)
    {
        $field = isset($queryData['field']) ? $queryData['field'] : null;
        $operator = isset($queryData['operator']) ? $queryData['operator'] : null;
        $value = isset($queryData['value']) ? $queryData['value'] : null;

        if(null == $field || null == $operator || null == $value){
            Log::error("In ".__FILE__.': Line: '.__LINE__.": Some important value is not set");
            return;
        }

        switch(trim($operator)){
            case 'eq'://Equal
                $query->where($field, $value);
                break;
            case 'neq'://Not Equal
                $query->where($field, '<>', $value);
                break;
            case 'gt':
                $query->where($field, '>', $value);
                break;
            case 'gte':
                $query->where($field, '>=', $value);
                break;
            case 'lt':
                $query->where($field, '<', $value);
                break;
            case 'lte':
                $query->where($field, '<=', $value);
                break;
            case 'startswith':
                $query->where($field, 'like', "{$value}%");
                break;
            case 'contains':
                $query->where($field, 'like', "%{$value}%");
                break;
            case 'doesnotcontain':
                $query->where($field, 'not like', "%{$value}%");
                break;
            case 'endswith':
                $query->where($field, 'like', "%{$value}");
                break;
            case 'isnull':
                $query->whereNull($field);
                break;
            case 'isnotnull':
                $query->whereNotNull($field);
                break;
            case 'isempty':
                $query->where($field,  '');
                break;
            case 'isnotempty':
                $query->where($field, '<>', '');
                break;
            default:
                Log::error("In ".__FILE__.': Line: '.__LINE__.": {$operator} is unknown");

        }
    }

    /**
     * Check if column has custom filter handler.
     *
     * @param  string $columnName
     * @return bool
     */
    public function hasCustomFilter($columnName)
    {
        return isset($this->columnDef['filter'][$columnName]);
    }

    /**
     * Perform filter column on selected field.
     *
     * @param mixed $query
     * @param string|Closure $method
     * @param mixed $parameters
     * @param string $column
     * @param string $keyword
     */
    protected function compileColumnQuery($query, $method, $parameters, $column, $keyword)
    {
        if (method_exists($query, $method)
            && count($parameters) <= with(new \ReflectionMethod($query, $method))->getNumberOfParameters()
        ) {
            if (Str::contains(Str::lower($method), 'raw')
                || Str::contains(Str::lower($method), 'exists')
            ) {
                call_user_func_array(
                    [$query, $method],
                    $this->parameterize($parameters, $keyword)
                );
            } else {
                call_user_func_array(
                    [$query, $method],
                    $this->parameterize($column, $parameters, $keyword)
                );
            }
        }
    }

    /**
     * Build Query Builder Parameters.
     *
     * @return array
     */
    protected function parameterize()
    {
        $args       = func_get_args();
        $keyword    = count($args) > 2 ? $args[2] : $args[1];
        $parameters = Helper::buildParameters($args);
        $parameters = Helper::replacePatternWithKeyword($parameters, $keyword, '$1');

        return $parameters;
    }

    /**
     * Get eager loads keys if eloquent.
     *
     * @return array
     */
    protected function getEagerLoads()
    {
        if ($this->query_type == 'eloquent') {
            return array_keys($this->query->getEagerLoads());
        }

        return [];
    }

    /**
     * Add relation query on global search.
     *
     * @param mixed $query
     * @param string $relation
     * @param string $column
     * @param string $keyword
     */
    protected function compileRelationSearch($query, $relation, $column, $keyword, $index)
    {
        $myQuery = clone $this->query;

        /**
         * For compile nested relation, we need store all nested relation as array
         * and reverse order to apply where query.
         * With this method we can create nested sub query with properly relation.
         */

        /**
         * Store all relation data that require in next step
         */
        $relationChunk = [];

        /**
         * Store last eloquent query builder for get next relation.
         */
        $lastQuery = $query;

        $relations    = explode('.', $relation);
        $lastRelation = end($relations);
        foreach ($relations as $relation) {
            $relationType = $myQuery->getModel()->{$relation}();
            $myQuery->orWhereHas($relation, function ($builder) use (
                $column,
                $keyword,
                $query,
                $relationType,
                $relation,
                $lastRelation,
                &$relationChunk,
                &$lastQuery,
                $index
            ) {
                $builder->select($this->connection->raw('count(1)'));

                // We will perform search on last relation only.
                if ($relation == $lastRelation) {
                    $this->compileQuerySearch($builder, $column, $keyword, '', $index);
                }

                // Put require object to next step!!
                $relationChunk[$relation] = [
                    'builder'      => $builder,
                    'relationType' => $relationType,
                    'query'        => $lastQuery,
                ];

                // This is trick make sub query.
                $lastQuery = $builder;
            });

            // This is trick to make nested relation by pass previous relation to be next query eloquent builder
            $myQuery = $relationType;
        }

        /**
         * Reverse them all
         */
        $relationChunk = array_reverse($relationChunk, true);

        /**
         * Create valuable for use in check last relation
         */
        end($relationChunk);
        $lastRelation = key($relationChunk);
        reset($relationChunk);

        /**
         * Walking ...
         */
        foreach ($relationChunk as $relation => $chunk) {
            // Prepare variables
            $builder  = $chunk['builder'];
            $query    = $chunk['query'];
            $bindings = $builder->getBindings();
            $builder  = "({$builder->toSql()}) >= 1";

            // Check if it last relation we will use orWhereRaw
            if ($lastRelation == $relation) {
                $relationMethod = "orWhereRaw";
            } else {
                // For case parent relation of nested relation.
                // We must use and for properly query and get correct result
                $relationMethod = "whereRaw";
            }

            $query->{$relationMethod}($builder, $bindings);
        }
    }

    /**
     * Compile query builder where clause depending on configurations.
     *
     * @param mixed $query
     * @param string $column
     * @param string $keyword
     * @param string $relation
     */
    protected function compileQuerySearch($query, $column, $keyword, $relation, $index)
    {
        $jsonField = $this->request->isJson($index);
        if(!$jsonField){
            $this->compileQueryNormalSearch($query, $column, $keyword, $relation);
        }else{
            $this->compileQueryJsonSearch($query, $column, $keyword, $jsonField, $relation, $index);
        }
    }

    /**
     * Compile query builder where clause depending on configurations.
     *
     * @param mixed $query
     * @param string $column
     * @param string $keyword
     * @param string $relation
     */
    protected function compileQueryNormalSearch($query, $column, $keyword, $relation = 'or')
    {
        $column = $this->addTablePrefix($query, $column);
        $column = $this->castColumn($column);
        $sql    = $column . ' LIKE ?';

        if ($this->isCaseInsensitive()) {
            $sql = 'LOWER(' . $column . ') LIKE ?';
        }

        $query->{$relation . 'WhereRaw'}($sql, [$this->prepareKeyword($keyword)]);
    }

    /**
     * @param $query
     * @param $column
     * @param $keyword
     * @param $jsonfield
     * @param string $relation
     * @param $index
     * @throws Exception
     */
    protected function compileQueryJsonSearch($query, $column, $keyword, $jsonfield, $relation = 'or', $index = 0)
    {
        $columnInput = $this->request->columns()[$index] ?? [];

        $column = $this->addTablePrefix($query, $column, true);
        //$column = $this->castColumn($column);

        $column = $this->castColumn($this->grammatizeJsonField($column . '->'.$jsonfield));

        if ($this->isCaseInsensitive()) {
            $sql = 'LOWER(' . $column . ') LIKE ?';
        }

        if(!$columnInput['fallback'] ?? true){
            $query->{$relation . 'WhereRaw'}($sql, [$this->prepareKeyword($keyword)]);
        }else{
            $columnFallback = $this->castColumn($this->wrap($columnInput['fallback']));
            if ($this->isCaseInsensitive()) {
                $fallbackSql = 'LOWER(' . $columnFallback . ') LIKE ?';
            }
            $query->{$relation . 'WhereRaw'}($sql.' or '.$fallbackSql, [$this->prepareKeyword($keyword), $this->prepareKeyword($keyword)]);
        }
    }

    /**
     * @param $field
     * @return string
     * @throws Exception
     */
    protected function grammatizeJsonField($field)
    {
        $fields = explode('->', $field);

        $column = $this->wrap($fields[0]);
        if ($this->database == 'mysql') {
            $jsonField = $this->quote("$.{$fields[1]}");
            return "{$column}->{$jsonField}";
        }else if($this->database == 'pgsql'){
            $jsonField = implode(',', explode('.', $fields[1]));
            $jsonField = $this->quote("{{$jsonField}}");
            return "{$column}#>>{$jsonField}";
        }else{
            throw new Exception($this->database." is Unknown for this kind of operation.");
        }
    }

    /**
     * Patch for fix about ambiguous field.
     * Ambiguous field error will appear when query use join table and search with keyword.
     *
     * @param mixed $query
     * @param string $column
     * @return string
     */
    protected function addTablePrefix($query, $column, $nowrap = false)
    {
        // Check if field does not have a table prefix
        if (strpos($column, '.') === false) {
            // Alternative method to check instanceof \Illuminate\Database\Eloquent\Builder
            if (method_exists($query, 'getQuery')) {
                $q = $query->getQuery();
            } else {
                $q = $query;
            }

            if (! $q->from instanceof Expression) {
                // Get table from query and add it.
                $column = $q->from . '.' . $column;
            }
        }

        return $nowrap ? $column : $this->wrap($column);
    }

    /**
     * Wrap a column and cast in pgsql.
     *
     * @param  string $column
     * @return string
     */
    protected function castColumn($column)
    {
        if ($this->database === 'pgsql') {
            $column = 'CAST(' . $column . ' as TEXT)';
        } elseif ($this->database === 'firebird') {
            $column = 'CAST(' . $column . ' as VARCHAR(255))';
        }

        return $column;
    }

    /**
     * Prepare search keyword based on configurations.
     *
     * @param string $keyword
     * @return string
     */
    protected function prepareKeyword($keyword)
    {
        if ($this->isCaseInsensitive()) {
            $keyword = Str::lower($keyword);
        }

        if ($this->isWildcard()) {
            $keyword = $this->wildcardLikeString($keyword);
        }

        if ($this->isSmartSearch()) {
            $keyword = "%$keyword%";
        }

        return $keyword;
    }

    /**
     * Perform column search.
     *
     * @return void
     */
    public function columnSearch()
    {
        $columns = $this->request->columns();

        foreach ($columns as $index => $column) {
            if (! $this->request->isColumnSearchable($index)) {
                continue;
            }

            $column = $this->getColumnName($index);

            if (isset($this->columnDef['filter'][$column])) {
                $columnDef = $this->columnDef['filter'][$column];
                // get a raw keyword (without wildcards)
                $keyword = $this->getSearchKeyword($index, true);
                $builder = $this->getQueryBuilder();

                if ($columnDef['method'] instanceof Closure) {
                    $whereQuery = $builder->newQuery();
                    call_user_func_array($columnDef['method'], [$whereQuery, $keyword]);
                    $builder->addNestedWhereQuery($whereQuery);
                } else {
                    $this->compileColumnQuery(
                        $builder,
                        $columnDef['method'],
                        $columnDef['parameters'],
                        $column,
                        $keyword
                    );
                }
            } else {
                if (count(explode('.', $column)) > 1) {
                    $eagerLoads     = $this->getEagerLoads();
                    $parts          = explode('.', $column);
                    $relationColumn = array_pop($parts);
                    $relation       = implode('.', $parts);
                    if (in_array($relation, $eagerLoads)) {
                        $column = $this->joinEagerLoadedColumn($relation, $relationColumn);
                    }
                }

                $keyword = $this->getSearchKeyword($index);
                $this->compileColumnSearch($index, $column, $keyword);
            }

            $this->isFilterApplied = true;
        }
    }

    /**
     * Get proper keyword to use for search.
     *
     * @param int $i
     * @param bool $raw
     * @return string
     */
    protected function getSearchKeyword($i, $raw = false)
    {
        $keyword = $this->request->columnKeyword($i);
        if ($raw || $this->request->isRegex($i)) {
            return $keyword;
        }

        return $this->setupKeyword($keyword);
    }

    /**
     * Join eager loaded relation and get the related column name.
     *
     * @param string $relation
     * @param string $relationColumn
     * @return string
     */
    protected function joinEagerLoadedColumn($relation, $relationColumn)
    {
        $lastQuery = $this->query;
        foreach (explode('.', $relation) as $eachRelation) {
            $model = $lastQuery->getRelation($eachRelation);
            switch (true) {
                case $model instanceof BelongsToMany:
                    $pivot   = $model->getTable();
                    $pivotPK = $model->getExistenceCompareKey();
                    $pivotFK = $model->getQualifiedParentKeyName();
                    $this->performJoin($pivot, $pivotPK, $pivotFK);

                    $related = $model->getRelated();
                    $table   = $related->getTable();
                    $tablePK = $related->getForeignKey();
                    $foreign = $pivot . '.' . $tablePK;
                    $other   = $related->getQualifiedKeyName();

                    $lastQuery->addSelect($table . '.' . $relationColumn);
                    $this->performJoin($table, $foreign, $other);

                    break;

                case $model instanceof HasOneOrMany:
                    $table   = $model->getRelated()->getTable();
                    $foreign = $model->getQualifiedForeignKeyName();
                    $other   = $model->getQualifiedParentKeyName();
                    break;

                case $model instanceof BelongsTo:
                    $table   = $model->getRelated()->getTable();
                    $foreign = $model->getQualifiedForeignKey();
                    $other   = $model->getQualifiedOwnerKeyName();
                    break;

                default:
                    $table = $model->getRelated()->getTable();
                    if ($model instanceof HasOneOrMany) {
                        $foreign = $model->getForeignKey();
                        $other   = $model->getQualifiedParentKeyName();
                    } else {
                        $foreign = $model->getQualifiedForeignKey();
                        $other   = $model->getQualifiedOtherKeyName();
                    }
            }
            $this->performJoin($table, $foreign, $other);
            $lastQuery = $model->getQuery();
        }

        return $table . '.' . $relationColumn;
    }

    /**
     * Perform join query.
     *
     * @param string $table
     * @param string $foreign
     * @param string $other
     */
    protected function performJoin($table, $foreign, $other)
    {
        $joins = [];
        foreach ((array) $this->getQueryBuilder()->joins as $key => $join) {
            $joins[] = $join->table;
        }

        if (! in_array($table, $joins)) {
            $this->getQueryBuilder()->leftJoin($table, $foreign, '=', $other);
        }
    }

    /**
     * Compile queries for column search.
     *
     * @param int $i
     * @param mixed $column
     * @param string $keyword
     */
    protected function compileColumnSearch($i, $column, $keyword)
    {
        if ($this->request->isRegex($i)) {
            $column = strstr($column, '(') ? $this->connection->raw($column) : $column;
            $this->regexColumnSearch($column, $keyword);
        } else {
            $this->compileQuerySearch($this->query, $column, $keyword, '', $i);
        }
    }

    /**
     * Compile regex query column search.
     *
     * @param mixed $column
     * @param string $keyword
     */
    protected function regexColumnSearch($column, $keyword)
    {
        if ($this->isOracleSql()) {
            $sql = ! $this->isCaseInsensitive() ? 'REGEXP_LIKE( ' . $column . ' , ? )' : 'REGEXP_LIKE( LOWER(' . $column . ') , ?, \'i\' )';
            $this->query->whereRaw($sql, [$keyword]);
        } elseif ($this->database == 'pgsql') {
            $sql = ! $this->isCaseInsensitive() ? $column . ' ~ ?' : $column . ' ~* ? ';
            $this->query->whereRaw($sql, [$keyword]);
        } else {
            $sql = ! $this->isCaseInsensitive() ? $column . ' REGEXP ?' : 'LOWER(' . $column . ') REGEXP ?';
            $this->query->whereRaw($sql, [Str::lower($keyword)]);
        }
    }

    /**
     * Perform sorting of columns.
     *
     * @throws Exception
     * @return void
     */
    public function ordering()
    {
        if ($this->orderCallback) {
            call_user_func($this->orderCallback, $this->getQueryBuilder());

            return;
        }

        foreach ($this->request->orderableColumns() as $orderable) {
            //$column = $this->getColumnName($orderable['column'], true);
            $column = $orderable['column'];

            if ($this->isBlacklisted($column) && ! $this->hasCustomOrder($column)) {
                continue;
            }

            if ($this->hasCustomOrder($column)) {
                $method     = $this->columnDef['order'][$column]['method'];
                $parameters = $this->columnDef['order'][$column]['parameters'];
                $this->compileColumnQuery(
                    $this->getQueryBuilder(),
                    $method,
                    $parameters,
                    $column,
                    $orderable['direction']
                );
            } else {
                $valid = 1;
                if (count(explode('.', $column)) > 1) {
                    $eagerLoads     = $this->getEagerLoads();
                    $parts          = explode('.', $column);
                    $relationColumn = array_pop($parts);
                    $relation       = implode('.', $parts);

                    if (in_array($relation, $eagerLoads)) {
                        // Loop for nested relations
                        // This code is check morph many or not.
                        // If one of nested relation is MorphToMany
                        // we will call joinEagerLoadedColumn.
                        $lastQuery     = $this->query;
                        $isMorphToMany = false;
                        foreach (explode('.', $relation) as $eachRelation) {
                            $relationship = $lastQuery->getRelation($eachRelation);
                            if (! ($relationship instanceof MorphToMany)) {
                                $isMorphToMany = true;
                            }
                            $lastQuery = $relationship;
                        }
                        if ($isMorphToMany) {
                            $column = $this->joinEagerLoadedColumn($relation, $relationColumn);
                        } else {
                            $valid = 0;
                        }
                    }
                }

                if ($valid == 1) {
                    $jsonField = $orderable['json'];
                    $fallbackField = $orderable['fallback'];
                    $order = $orderable['direction'];
                    if($jsonField){
                        $this->getQueryBuilder()->orderByRaw($this->buildOrderByForJson($column, $jsonField, $fallbackField, $order));
                    }else{
                        if ($this->nullsLast) {
                            $this->getQueryBuilder()->orderByRaw($this->getNullsLastSql($column, $orderable['direction']));
                        }else{
                            $this->getQueryBuilder()->orderBy($column, $orderable['direction']);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $column
     * @param $jsonField
     * @param $fallbackField
     * @param $order
     * @return string
     * @throws Exception
     */
    protected function buildOrderByForJson($column, $jsonField, $fallbackField, $order)
    {
        $order = $order === 'asc' ? 'asc' : 'desc';

        if($fallbackField){
            $fallbackField = $this->wrap($fallbackField);
            $orderByClause = $this->grammatizeJsonField("{$column}->{$jsonField}")." {$order}, {$fallbackField} {$order}";
        }else{
            $orderByClause = $this->grammatizeJsonField("{$column}->{$jsonField}")." {$order}";
        }

        return $orderByClause;
    }

    protected function quote($data)
    {
        return $this->connection->getPdo()->quote($data);
    }

    /**
     * Check if column has custom sort handler.
     *
     * @param string $column
     * @return bool
     */
    protected function hasCustomOrder($column)
    {
        return isset($this->columnDef['order'][$column]);
    }

    /**
     * Get NULLS LAST SQL.
     *
     * @param  string $column
     * @param  string $direction
     * @return string
     */
    protected function getNullsLastSql($column, $direction)
    {
        $sql = Config::get('datatables.nulls_last_sql', '%s %s NULLS LAST');

        return sprintf($sql, $column, $direction);
    }

    /**
     * Perform pagination
     *
     * @return void
     */
    public function paging()
    {
        $start = (((int)$this->request->page()) - 1) * ((int) $this->request->pageSize());
        $length = (int) $this->request->pageSize() > 0 ? $this->request->pageSize() : 10;
        $this->query->skip($start)
                    ->take($length);
    }

    /**
     * Get results
     *
     * @return array|static[]
     */
    public function results()
    {
        return $this->query->get();
    }

    /**
     * Add column in collection.
     *
     * @param string $name
     * @param string|callable $content
     * @param bool|int $order
     * @return $this
     */
    public function addColumn($name, $content, $order = false)
    {
        $this->pushToBlacklist($name);

        return parent::addColumn($name, $content, $order);
    }
}
