<?php

namespace Odb;

class MysqliSqlBuilder
{
    /** @var \mysqli */
    protected $db;
    protected $prefix;

    /** @var \mysqli_stmt */
    protected $smt;
    /** @var \mysqli_result */
    protected $result;
    protected $table;
    protected $sql;
    protected $params = [];

    protected $sqlSlice = [
        'distinct' => '', 'columns' => '', 'table' => '', 'join' => '', 'where' => '', 'group_by' => '',
        'having' => '', 'order_by' => '', 'limit' => '', 'update' => '', 'insert' => '', 'delete' => ''
    ];
    protected $paramSlice = [
        'allow' => [], 'join' => [], 'where' => [], 'having' => [], 'insert' => [], 'update' => []
    ];

    public function __construct($db, string $prefix = '')
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * usage: table('user') || table('user as u') || table('user u');
     * @param string $table
     * @return $this
     */
    public function table(string $table)
    {
        $table = trim($table);
        $foffset = strpos($table, ' ');
        if ($foffset === false) {
            $tableName = $table;
            $alias = '';

        } else {
            $tableName = substr($table, 0, $foffset);

            $soffset = strrpos($table, ' ');
            $alias = ' as `' . $this->prefix . substr($table, $soffset+1) . '`';
        }
        $this->table = $this->prefix . $tableName;
        $this->sqlSlice['table'] = ' `' . $this->table . '`' . $alias;
        return $this;
    }

    /**
     * usage: distinct()
     * @return $this
     */
    public function ditinct()
    {
        $this->sqlSlice['distinct'] = ' distinct';
        return $this;
    }

    /**
     * usage: select(['id', 'age']) || select('id', 'age') || select('gradescore as score', 'sum(user.score) num')
     * @param $columns
     * @return $this
     */
    public function select($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        if ($columns === []) {
            $this->sqlSlice['columns'] = ' * ';
            return $this;
        }
        $columnSql = '';
        foreach ($columns as $column) {
            // 处理可能带有函数的列名
            $func = '@';
            $table = '';
            $arr = explode('(', $column);
            if (count($arr) === 2) {
                $func = $arr[0] . '(@)';
                $column = $arr[1];
            }
            // 处理可能带有表名的列名
            $arr = explode('.', $column);
            if (count($arr) === 2) {
                $table = '`' . $this->prefix . trim($arr[0]) . '`.';
                $column = $arr[1];
            }
            $column = trim($column, ' )');
            $foffset = strpos($column, ' ');
            if ($foffset === false) { // 获取列名
                $realcolumn = $column;
                $alias = '';
            } else {
                $realcolumn = rtrim(substr($column, 0, $foffset), ' )');
                $soffset = strrpos($column, ' ');
                $alias = ' as `' . substr($column, $soffset+1) . '`';
            }
            if ($realcolumn !== '*') { // *号不添加``
                $realcolumn = '`' . $realcolumn . '`';
            }
            $column = $table . $realcolumn;
            $column = str_replace('@', $column, $func);
            $columnSql .= $column . $alias . ',';
        }
        $this->sqlSlice['columns'] = ' '.rtrim($columnSql, ',').' ';
        return $this;
    }

    /**
     * usage: where('id', '=', 2) || where([['id', '=', 2], ['age', '>', 24]]) || where('id > ?', [45])
     * || where(function($query){$query->where('age', '>', 24)}) || where('id', 2)
     * @param array ...$where
     * @return $this
     */
    public function where(...$where)
    {
        $this->_where('and', ...$where);
        return $this;
    }

    /**
     * usage: orWhere('id', '=', 2) || orWhere([['id', '=', 2], ['age', '>', 24]]) || orWhere('id > ?', [45])
     * || orWhere(function($query){$query->where('age', '>', 24)}) || orWhere('id', 2)
     * @param array ...$where
     * @return $this
     */
    public function orWhere(...$where)
    {
        $this->_where('or', ...$where);
        return $this;
    }

    /**
     * usage: whereRaw('test_sup | 2 = ?', [6])
     * @param string $whereSql
     * @param array $whereParams
     * @return $this
     */
    public function whereRaw(string $whereSql, array $whereParams)
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = ' where ' . $whereSql;
        } else {
            if ($whereSql && substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' .  $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $whereParams);
        return $this;
    }

    /**
     * usage: whereNull('name')
     * @param string $column
     * @return $this
     */
    public function whereNull(string $column)
    {
        $column = $this->column($column);
        $whereSql = $column . ' is null';
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        return $this;
    }

    /**
     * usage: whereNotNull('name')
     * @param string $column
     * @return $this
     */
    public function whereNotNull(string $column)
    {
        $column = $this->column($column);
        $whereSql = $column . ' is not null';
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        return $this;
    }

    /**
     * usage: whereBetween('age', [22, 26])
     * @param string $column
     * @param array $between
     * @return $this
     */
    public function whereBetween(string $column, array $between)
    {
        $column = $this->column($column);
        $whereSql = $column . " between ? and ?";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $between);
        return $this;
    }

    /**
     * usage: whereNotBetween('age', [0, 18])
     * @param string $column
     * @param array $between
     * @return $this
     */
    public function whereNotBetween(string $column, array $between)
    {
        $column = $this->column($column);
        $whereSql = $column . " not between ? and ?";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $between);
        return $this;
    }

    /**
     * usage: whereIn('id', [1,3,5,6])
     * @param string $column
     * @param array $in
     * @return $this
     */
    public function whereIn(string $column, array $in)
    {
        $column = $this->column($column);
        $place_holders = implode(',', array_fill(0, count($in), '?'));
        $whereSql = $column . " in ({$place_holders})";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $in);
        return $this;
    }

    /**
     * usage: whereNotIn('id', [2,3])
     * @param string $column
     * @param array $in
     * @return $this
     */
    public function whereNotIn(string $column, array $in)
    {
        $column = $this->column($column);
        $place_holders = implode(',', array_fill(0, count($in), '?'));
        $whereSql = $column . " not in ({$place_holders})";
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $in);
        return $this;
    }

    /**
     * usage: whereColumn('id', '>', 'parent_id')
     * @param $where
     * @return $this
     */
    public function whereColumn($where)
    {
        if (empty($where)) return $this;
        $whereSql = '';
        if (is_array($where)) { // Two-dimensional array
            $whereSql .= '(';
            foreach ($where as $val) {
                $column1 = $this->column($val[0]);
                $column2 = $this->column($val[2]);
                $whereSql .= $column1.' '.$val[1].' '.$column2.' and';
            }
            $whereSql = substr($whereSql, 0, -4) . ')';
        } else { // Simple parameters
            $params = func_get_args();
            $column1 = $this->column($params[0]);
            $column2 = $this->column($params[2]);
            $whereSql .= $column1.' '.$params[1].' '.$column2;
        }
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where ' . $whereSql;
        } else {
            if (substr($this->sqlSlice['where'], -1) !== '(') $whereSql = ' and ' . $whereSql;
            $this->sqlSlice['where'] .= $whereSql;
        }
        return $this;
    }

    /**
     * usage: table('user as u')->join('article as a', 'u.id', '=', 'a.uid') ||
     * join('role', 'test_user.role_id=test_role.id and test_role.status>?', [1])
     * @param array ...$args
     * @return $this
     */
    public function join(...$args)
    {
        $this->_join('inner join ', ...$args);
        return $this;
    }

    /**
     * usage: same as join
     * @param array ...$args
     * @return $this
     */
    public function leftJoin(...$args)
    {
        $this->_join('left join ', ...$args);
        return $this;
    }

    /**
     * usage: same as join
     * @param array ...$args
     * @return $this
     */
    public function rightJoin(...$args)
    {
        $this->_join('right join ', ...$args);
        return $this;
    }

    /**
     * usage: orderBy('id', 'desc')
     * @param string $column
     * @param string $order
     * @return $this
     */
    public function orderBy(string $column, string $order)
    {
        $column = $this->column($column);
        if ($this->sqlSlice['order_by'] === '') {
            $this->sqlSlice['order_by'] = ' order by ' . $column . ' ' . $order;
        } else {
            $this->sqlSlice['order_by'] .= ',' .  $column . ' ' . $order;
        }
        return $this;
    }

    /**
     * usage: groupBy('username') || groupBy(['username', 'age']) || groupBy('username', 'age')
     * @param $column
     * @return $this
     */
    public function groupBy($column)
    {
        $columns = is_array($column) ? $column : func_get_args();
        $columnStr = '';
        foreach ($columns as $value) {
            $columnStr .= $this->column($value) . ',';
        }
        $columnStr = rtrim($columnStr, ',');
        $this->sqlSlice['group_by'] = ' group by ' . $columnStr . ' ';
        return $this;
    }

    /**
     * usage: having('num', '>', 2) || having('num > ?', [2])
     * @return $this
     */
    public function having(...$params)
    {
        $havingSql = ' having ';
        if (isset($params[2])) { // Simple parameters
            $column = $this->column($params[0]);
            $havingSql .= "{$column} {$params[1]} ?";
            $this->paramSlice['having'][] = $params[2];
        } else { // Native sql
            $havingSql .= $params[0];
            $this->paramSlice['having'] = $params[1];
        }
        $this->sqlSlice['having'] = $havingSql;
        return $this;
    }

    /**
     * usage: limit(3) || limit(2,3)
     * @param int $limit
     * @param int $length
     * @return $this
     */
    public function limit(int $limit, int $length = 0)
    {
        $this->sqlSlice['limit'] = ' limit ' . intval($limit);
        if ($length) $this->sqlSlice['limit'] .= ',' . intval($length);
        return $this;
    }

    /**
     * used for insert,  usage: allow('name', 'age') || allow(['name', 'age'])
     * @param $columns
     * @return $this
     */
    public function allow($columns)
    {
        $allows = is_array($columns) ? $columns : func_get_args();
        foreach ($allows as $a) {
            $this->paramSlice['allow'][$a] = '';
        }
        return $this;
    }

    /**
     * usage: insert(['id' => 2, 'name' => 'jack']) || insert([['name' => 'jack'], ['name' => 'linda']])
     * @param array $insert
     * @return int
     * @throws \Exception
     */
    public function insert(array $insert) : int
    {
        if (!$insert) return 0;
        $columns = '(';
        $values = '';
        $allow = $this->paramSlice['allow'];
        $filter = ($allow !== []) ? true : false;
        if (isset($insert[0]) && is_array($insert[0])) {
            if (!$insert[0]) return 0;
            $time = 0;
            foreach ($insert as $val) {
                $values .= '(';
                foreach ($val as $k => $v) {
                    if (!$filter || isset($allow[$k])) {
                        if ($time == 0) $columns .= '`' . $k . '`,';
                        $values .= '?,';
                        $this->paramSlice['insert'][] = $v;
                    }
                }
                ++$time;
                $values = rtrim($values, ',') . '),';
            }
            $values = rtrim($values, ',');
            $columns = rtrim($columns, ',') . ')';
        } else {
            $values .= '(';
            foreach ($insert as $key => $val) {
                if (!$filter || isset($allow[$key])) {
                    $columns .= '`' . $key . '`,';
                    $values .= '?,';
                    $this->paramSlice['insert'][] = $val;
                }
            }
            $values = rtrim($values, ',') . ')';
            $columns = rtrim($columns, ',') . ')';
        }
        $this->sqlSlice['insert'] = $columns . ' values ' . $values;
        $this->resolve('insert');
        $this->_exec();
        return $this->smt->affected_rows;
    }

    /**
     * usage: insertGetId(['id' => 2, 'name' => 'jack'])
     * @param array $insert
     * @return int
     * @throws \Exception
     */
    public function insertGetId(array $insert) : int
    {
        if (!$insert) return 0;
        $columns = '(';
        $values = '(';
        $allow = $this->paramSlice['allow'];
        $filter = ($allow !== []) ? true : false;
        foreach ($insert as $key => $val) {
            if (!$filter || isset($allow[$key])) {
                $columns .= '`' . $key . '`,';
                $values .= '?,';
                $this->paramSlice['insert'][] = $val;
            }
        }
        $values = rtrim($values, ',') . ')';
        $columns = rtrim($columns, ',') . ')';
        $this->sqlSlice['insert'] = $columns . ' values ' . $values;
        $this->resolve('insert');
        $this->_exec();
        return $this->smt->insert_id;
    }

    /**
     * usage: update(['id' => 2, 'name' => 'jack'])
     * @param array $update
     * @return int
     * @throws \Exception
     */
    public function update(array $update) : int
    {
        if (!$update) return 0;
        $updateSql = 'set ';
        $allow = $this->paramSlice['allow'];
        $filter = ($allow !== []) ? true : false;
        foreach ($update as $key => $val) {
            if (!$filter || isset($allow[$key])) {
                $updateSql .= '`' . $key . '`=?,';
                $this->paramSlice['update'][] = $val;
            }
        }
        $this->sqlSlice['update'] = rtrim($updateSql, ',');
        $this->resolve('update');
        $this->_exec();
        return $this->smt->affected_rows;
    }

    /**
     * usage: increment('score') || increment('score', 2) || increment([['score', 1], ['level', 9]]);
     * @param $increment
     * @return int
     * @throws \Exception
     */
    public function increment($increment) : int
    {
        $updateSql = 'set ';
        if (is_array($increment)) {
            foreach ($increment as $val) {
                $field = '`' . $val[0] . '`';
                $updateSql .= $field . '=' . $field . '+?,';
                $this->paramSlice['update'][] = $val[1];
            }
            $this->sqlSlice['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $incr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . $args[0] . '`';
            $this->sqlSlice['update'] = $updateSql . $field . '=' . $field . '+?';
            $this->paramSlice['update'][] = $incr;
        }
        $this->resolve('update');
        $this->_exec();
        return $this->smt->affected_rows;
    }

    /**
     * usage: increment('score') || increment('score', 2) || increment([['score', 1], ['level', 9]]);
     * @param $decrement
     * @return int
     * @throws \Exception
     */
    public function decrement($decrement) : int
    {
        $updateSql = 'set ';
        if (is_array($decrement)) {
            foreach ($decrement as $val) {
                $field = '`' . $val[0] . '`';
                $updateSql .= $field . '=' . $field . '-?,';
                $this->paramSlice['update'][] = $val[1];
            }
            $this->sqlSlice['update'] = rtrim($updateSql, ',');
        } else {
            $args = func_get_args();
            $decr = count($args) === 2 ? $args[1] : 1;
            $field = '`' . $args[0] . '`';
            $this->sqlSlice['update'] = $updateSql . $field . '=' . $field . '-?';
            $this->paramSlice['update'][] = $decr;
        }
        $this->resolve('update');
        $this->_exec();
        return $this->smt->affected_rows;
    }

    public function delete() : int
    {
        $this->resolve('delete');
        $this->_exec();
        return $this->smt->affected_rows;
    }

    public function getSql() : string
    {
        $this->resolve();
        return $this->resolveSql();
    }

    protected function resolveSql()
    {
        if (!$this->sql) return '';
        $arr = explode('?', $this->sql);
        $sql = '';
        foreach ($arr as $k => $v) {
            $sql .= $v . ($this->params[$k] ?? '');
        }
        if (!$sql) $sql = $arr[0];
        return $sql;
    }

    public function get() : array
    {
        $this->resolve();
        $this->_exec();
        $result = $this->smt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function first() : ?array
    {
        $this->resolve();
        $this->_exec();
        $this->result = $this->smt->get_result();
        $res = $this->result->fetch_assoc();
        return $res ? $res : null;
    }

    public function pluck(string $col, string $key = '') : array
    {
        $columns[] = $col;
        ($key !== '') && ($columns[] = $key);
        $res = $this->select($columns)->get();
        if ($res === []) return $res;
        $col = trim($col);
        $offset = strpos($col, '.');
        if ($offset !== false) {
            $col = ltrim(substr($col, $offset+1));
        }
        if ($key !== '') {
            $data = [];
            $key = trim($key);
            $offset = strpos($key, '.');
            if ($offset !== false) {
                $key = ltrim(substr($key, $offset+1));
            }
            foreach ($res as $val) {
                $data[$val[$key]] = $val[$col];
            }
            return $data;
        }
        return array_column($res, $col);
    }

    public function value(string $column) : ?string
    {
        $res = $this->select($column)->first();
        if ($res === null) return null;
        return $res[$column];
    }

    public function max(string $column) : int
    {
        $res = $this->select('max('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function min(string $column) : int
    {
        $res = $this->select('min('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function sum(string $column) : int
    {
        $res = $this->select('sum('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function count() : int
    {
        $res = $this->select('count(*) as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function avg(string $column) : int
    {
        $res = $this->select('avg('.$column.') as num')->first();
        if ($res === null) return 0;
        return $res['num'];
    }

    public function beginTrans()
    {
        $this->db->begin_transaction();
    }

    public function rollBack()
    {
        $this->db->rollBack();
    }

    public function commit()
    {
        $this->db->commit();
    }

    public function prepare(string $sql)
    {
        $this->smt = $this->db->prepare($sql);
        return $this;
    }

    public function execute(array $params = [])
    {
        $bind = str_repeat('s', count($params));
        $this->smt->bind_param('s', ...$params);
        $this->smt->execute();
        $this->result = $this->smt->get_result();
        return $this;
    }

    public function rowCount() : int
    {
        return $this->smt->affected_rows;
    }

    public function lastInsertId() : int
    {
        return $this->db->insert_id;
    }

    public function query(string $sql)
    {
        $this->result = $this->db->query($sql);
        return $this;
    }

    public function fetchAll() : array
    {
        return $this->result->fetch_all(MYSQLI_ASSOC);
    }

    public function fetch() : ?array
    {
        return $this->result->fetch_assoc();
    }

    protected function resolve(string $option = 'select') : void
    {
        if ($option === 'select') {
            $columns = $this->sqlSlice['columns'] ? $this->sqlSlice['columns'] : ' * ';
            $this->sql = 'select' . $this->sqlSlice['distinct'] . $columns . 'from'.
                $this->sqlSlice['table'] .' '. $this->sqlSlice['join'] . $this->sqlSlice['where'] .
                $this->sqlSlice['group_by'] . $this->sqlSlice['having'] . $this->sqlSlice['order_by'] .
                $this->sqlSlice['limit'];
            $this->params = array_merge($this->paramSlice['join'], $this->paramSlice['where'], $this->paramSlice['having']);
//            $this->sql = rtrim($this->sql);
        } elseif ($option === 'update') {
            $where = $this->sqlSlice['where'] ?: 'where 1=2';
            $this->sql = 'update ' . $this->sqlSlice['table'] . ' ' . $this->sqlSlice['update'] . ' ' . $where;
            $this->params = array_merge($this->paramSlice['update'], $this->paramSlice['where']);
        } elseif ($option === 'insert') {
            $this->sql = 'insert into ' . $this->sqlSlice['table'] . ' ' . $this->sqlSlice['insert'];
            $this->params = $this->paramSlice['insert'];
        } elseif ($option === 'delete') {
            $where = $this->sqlSlice['where'] ?: 'where 1=2';
            $this->sql = 'delete from ' . $this->sqlSlice['table'] . ' ' . $where;
            $this->params = $this->paramSlice['where'];
        }
    }

    protected function _exec()
    {
        try {
            $this->smt = $this->db->prepare($this->sql);
            $bind = str_repeat('s', count($this->params));
            $this->smt->bind_param($bind, ...$this->params);
            $this->smt->execute();
        } catch (\Exception $e) {
            $sql = $this->resolveSql();
            throw new \Exception($e->getMessage().' ; sql: ' . $sql);
        }

    }

    protected function _join($joinSql, ...$args) : void
    {
        $args[0] = trim($args[0]);
        $foffset = strpos($args[0], ' ');
        if ($foffset === false) {
            $tableName = $args[0];
            $alias = '';
        } else {
            $tableName = substr($args[0], 0, $foffset);
            $soffset = strrpos($args[0], ' ');
            $alias = ' as `' . $this->prefix.substr($args[0], $soffset+1) . '`';
        }
        $joinSql .= '`' . $this->prefix . $tableName . '`' . $alias;
        if (isset($args[3])) { // Simple parameters
            $offset1 = strpos($args[1], '.');
            $offset2 = strpos($args[3], '.');

            if ($offset1 !== false) {
                $join1 = '`' . $this->prefix . trim(substr($args[1],0, $offset1)) . '`.`' . trim(substr($args[1], $offset1+1)) . '`';
            } else {
                $join1 = '`' . trim($args[1]) . '`';
            }
            if ($offset2 !== false) {
                $join2 = '`' . $this->prefix . trim(substr($args[3],0, $offset2)) . '`.`' . trim(substr($args[3], $offset2+1)) . '`';
            } else {
                $join2 = '`' . $args[3] . '`';
            }
            $joinSql .= " on {$join1}{$args[2]}{$join2}";
        } else { // Native sql
            $joinSql .= " on $args[1]";
            $this->paramSlice['join'] = array_merge($this->paramSlice['join'], $args[2]);
        }
        $this->sqlSlice['join'] .= ' ' . $joinSql . ' ';
    }

    protected function orGroupBegin()
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where (';
        } else {
            $this->sqlSlice['where'] .= ' or (';
        }
        return $this;
    }

    protected function andGroupBegin()
    {
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = 'where (';
        } else {
            $this->sqlSlice['where'] .= ' and (';
        }
        return $this;
    }

    protected function groupEnd() : void
    {
        $this->sqlSlice['where'] .= ')';
    }

    protected function _where($relation, ...$where) : void
    {
        if (empty($where[0])) return;
        $whereSql = '';
        $whereParams = [];
        if (is_array($where[0])) { // Two-dimensional array
            $whereSql .= '(';
            foreach ($where[0] as $val) {
                $column = $this->column($val[0]);
                if (isset($val[2])) {
                    $whereSql .= $column.' '.$val[1].' ? and ';
                    $whereParams[] = $val[2];
                } else {
                    $whereSql .= $column.' = ? and ';
                    $whereParams[] = $val[1];
                }
            }
            $whereSql = substr($whereSql, 0, -5) . ')';
        } elseif (!is_string($where[0]) && is_callable($where[0])) { // Closure,此处不能是函数名
            if ($relation === 'and') {
                $where[0]($this->andGroupBegin());
            } else {
                $where[0]($this->orGroupBegin());
            }
            $this->groupEnd();
        } else {
            if (isset($where[2])) {
                $column = $this->column($where[0]);
                $whereSql .= $column.' '.$where[1].' ?';
                $whereParams[] = $where[2];
            } else {
                $column = $this->column($where[0]);
                $whereSql .= $column.' = ?';
                $whereParams[] = $where[1];
            }
        }
        if ($this->sqlSlice['where'] === '') {
            $this->sqlSlice['where'] = ' where ' . $whereSql;
        } else {
            if ($whereSql && substr($this->sqlSlice['where'], -1) !== '(') $whereSql = " {$relation} {$whereSql}";
            $this->sqlSlice['where'] .= $whereSql . ' ';
        }
        $this->paramSlice['where'] = array_merge($this->paramSlice['where'], $whereParams);
    }

    protected function column(string $column) : string
    {
        $offset = strpos($column, '.');
        $table = '';
        if ($offset !== false) {
            $table = '`' . $this->prefix . rtrim(substr($column, 0, $offset)) . '`.';
            $column = ltrim(substr($column, $offset+1));
        }
        return $table . '`' . $column . '`';
    }

}