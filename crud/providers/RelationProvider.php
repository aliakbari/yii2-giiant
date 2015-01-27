<?php
/**
 * Created by PhpStorm.
 * User: tobias
 * Date: 14.03.14
 * Time: 10:21
 */

namespace schmunk42\giiant\crud\providers;

use yii\helpers\Inflector;

class RelationProvider extends \schmunk42\giiant\base\Provider
{
    public function activeField($column)
    {
        $relation = $this->generator->getRelationByColumn($this->generator->modelClass, $column);
        if ($relation) {
            switch (true) {
                case (!$relation->multiple):
                    $pk     = key($relation->link);
                    $name   = $this->generator->getModelNameAttribute($relation->modelClass);
                    $method = __METHOD__;
                    $code   = <<<EOS
// generated by {$method}
\$form->field(\$model, '{$column->name}')->dropDownList(
    \yii\helpers\ArrayHelper::map({$relation->modelClass}::find()->all(),'{$pk}','{$name}'),
    ['prompt' => {$this->generator->generateString('Select')}]
);
EOS;
                    return $code;
                default:
                    return null;

            }
        }
    }

    public function attributeFormat($column)
    {
        // do not handle columns with a primary key, TOOD: review(!) should not be omitted in every case
        if ($column->isPrimaryKey) {
            return null;
        }

        $relation = $this->generator->getRelationByColumn($this->generator->modelClass, $column);
        if ($relation) {
            if ($relation->multiple) {
                return null;
            }
            $title          = $this->generator->getModelNameAttribute($relation->modelClass);
            $route          = $this->generator->createRelationRoute($relation, 'view');
            $relationGetter = 'get' . Inflector::id2camel(
                    str_replace('_id', '', $column->name),
                    '_'
                ) . '()'; // TODO: improve detection
            $params         = "'id' => \$model->{$column->name}";

            $relationModel   = new $relation->modelClass;
            $pks             = $relationModel->primaryKey();
            $paramArrayItems = "";
            foreach ($pks as $attr) {
                $paramArrayItems .= "'{$attr}' => \$model->{$relationGetter}->one()->{$attr},";
            }

            $method = __METHOD__;
            $code   = <<<EOS
// generated by {$method}
[
    'format'=>'html',
    'attribute'=>'$column->name',
    'value' => (\$model->{$relationGetter}->one() ? Html::a(\$model->{$relationGetter}->one()->{$title}, ['{$route}', {$paramArrayItems}]) : '<span class="label label-warning">?</span>'),
]
EOS;
            return $code;
        }
    }

    public function columnFormat($column, $model)
    {
        // do not handle columns with a primary key, TOOD: review(!) should not be omitted in every case
        if ($column->isPrimaryKey) {
            return null;
        }

        $relation = $this->generator->getRelationByColumn($model, $column);
        if ($relation) {
            if ($relation->multiple) {
                return null;
            }
            $title          = $this->generator->getModelNameAttribute($relation->modelClass);
            $route          = $this->generator->createRelationRoute($relation, 'view');
            $method         = __METHOD__;
            $relationGetter = 'get' . Inflector::id2camel(
                    str_replace('_id', '', $column->name),
                    '_'
                ) . '()'; // TODO: improve detection

            $pk              = key($relation->link);
            $relationModel   = new $relation->modelClass;
            $pks             = $relationModel->primaryKey();
            $paramArrayItems = "";
            foreach ($pks as $attr) {
                $paramArrayItems .= "'{$attr}' => \$rel->{$attr},";
            }
            $code = <<<EOS
// generated by {$method}
[
            "class" => yii\\grid\\DataColumn::className(),
            "attribute" => "{$column->name}",
            "value" => function(\$model){
                if (\$rel = \$model->{$relationGetter}->one()) {
                    return yii\helpers\Html::a(\$rel->{$title},["{$route}", {$paramArrayItems}],["data-pjax"=>0]);
                } else {
                    return '';
                }
            },
            "format" => "raw",
]
EOS;
            return $code;
        }
    }


    // TODO: params is an array, because we need the name, improve params
    public function relationGrid($data)
    {
        $name           = $data[1];
        $relation       = $data[0];
        $showAllRecords = isset($data[2]) ? $data[2] : false;
        $model          = new $relation->modelClass;
        $counter        = 0;
        $columns        = '';

        foreach ($model->attributes AS $attr => $value) {
            // max seven columns
            if ($counter > 8) {
                continue;
            }
            // skip virtual attributes
            if (!isset($model->tableSchema->columns[$attr])) {
                continue;
            }
            // don't show current model
            if (key($relation->link) == $attr) {
                continue;
            }

            $code = $this->generator->columnFormat($model->tableSchema->columns[$attr], $model);
            if ($code == false) {
                continue;
            }
            $columns .= $code . ",\n";
            $counter++;
        }

        $reflection = new \ReflectionClass($relation->modelClass);
        if (!$this->generator->isPivotRelation($relation)) {
            $template          = '{view} {update}';
            $deleteButtonPivot = '';
        } else {
            $template          = '{view} {delete}';
            $returnUrl = <<<EOS
(Tabs::getParentRelationRoute(\\Yii::\$app->controller->id) !== null) ?
                                Tabs::getParentRelationRoute(\\Yii::\$app->controller->id) : null
EOS;
            $deleteButtonPivot = <<<EOS
'delete' => function (\$url, \$model) {
                \$returnTo = {$returnUrl};
                return yii\helpers\Html::a('<span class="glyphicon glyphicon-remove"></span>', \$url . '&returnUrl=' . \$returnTo, [
                    'class' => 'text-danger',
                    'title'         => {$this->generator->generateString('Remove')},
                    'data-confirm'  => {$this->generator->generateString('Are you sure you want to delete the related item?')},
                    'data-method' => 'post',
                    'data-pjax' => '0',
                ]);
            },
'view' => function (\$url, \$model) {
                \$returnTo = {$returnUrl};
                return yii\helpers\Html::a(
                    '<span class="glyphicon glyphicon-cog"></span>',
                    \$url . '&returnUrl=' . \$returnTo,
                    [
                        'data-title'  => {$this->generator->generateString('View Pivot Record')},
                        'data-toggle' => 'tooltip',
                        'data-pjax'   => '0',
                        'class'        => 'text-muted'
                    ]
                );
            },
EOS;
        }

        $controller   = $this->generator->pathPrefix . Inflector::camel2id($reflection->getShortName(), '-', true);
        $actionColumn = <<<EOS
[
    'class'      => 'yii\grid\ActionColumn',
    'template'   => '$template',
    'contentOptions' => ['nowrap'=>'nowrap'],
    'urlCreator' => function(\$action, \$model, \$key, \$index) {
        // using the column name as key, not mapping to 'id' like the standard generator
        \$returnUrl = (Tabs::getParentRelationRoute(\\Yii::\$app->controller->id) !== null) ?
                        Tabs::getParentRelationRoute(\\Yii::\$app->controller->id) : null;
        \$params = is_array(\$key) ? \$key : [\$model->primaryKey()[0] => (string) \$key, 'returnUrl' => \$returnUrl];
        \$params[0] = '$controller' . '/' . \$action;
        return Url::toRoute(\$params);
    },
    'buttons'    => [
        $deleteButtonPivot
    ],
    'controller' => '$controller'
]
EOS;
        $columns .= $actionColumn . ",";

        $query = $showAllRecords ?
            "'query' => \\{$relation->modelClass}::find()" :
            "'query' => \$model->get{$name}()";
        $code  = '';
        $code .= <<<EOS
\\yii\\grid\\GridView::widget([
    'dataProvider' => new \\yii\\data\\ActiveDataProvider([{$query}, 'pagination' => ['pageSize' => 10]]),
    'columns' => [$columns]
]);
EOS;
        return $code;
    }


}