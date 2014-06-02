<?php

namespace mdm\admin\components;

use Yii;
use yii\helpers\Inflector;
use yii\caching\GroupDependency;
use ReflectionClass;
use mdm\admin\models\Menu;

/**
 * Description of AccessHelper
 *
 * @author MDMunir
 */
class AccessHelper
{
    const FILE_GROUP = 'file';
    const AUTH_GROUP = 'auth';

    /**
     * 
     * @return array
     */
    public static function getRoutes()
    {
        $key = static::buildKey(__METHOD__);
        if (($cache = Yii::$app->getCache()) === null || ($result = $cache->get($key)) === false) {
            $result = [];
            self::getRouteRecrusive(Yii::$app, $result);
            if ($cache !== null) {
                $cache->set($key, $result, 0, new GroupDependency([
                    'group' => static::getGroup(static::FILE_GROUP)
                ]));
            }
        }
        return $result;
    }

    /**
     * 
     * @param \yii\base\Module $module
     * @param array $result
     */
    private static function getRouteRecrusive($module, &$result)
    {
        foreach ($module->getModules() as $id => $child) {
            if (($child = $module->getModule($id)) !== null) {
                self::getRouteRecrusive($child, $result);
            }
        }
        /* @var $controller \yii\base\Controller */
        foreach ($module->controllerMap as $id => $value) {
            $controller = Yii::createObject($value, [$id, $module]);
            self::getActionRoutes($controller, $result);
            $result[] = '/' . $controller->uniqueId . '/*';
        }

        $namespace = trim($module->controllerNamespace, '\\') . '\\';
        self::getControllerRoutes($module, $namespace, '', $result);
        $result[] = ($module->uniqueId === '' ? '' : '/' . $module->uniqueId) . '/*';
    }

    private static function getControllerRoutes($module, $namespace, $prefix, &$result)
    {
        $path = Yii::getAlias('@' . str_replace('\\', '/', $namespace));
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            if (is_dir($path . '/' . $file)) {
                self::getControllerRoutes($module, $namespace . $file . '\\', $prefix . $file . '/', $result);
            } elseif (strcmp(substr($file, -14), 'Controller.php') === 0) {
                $id = Inflector::camel2id(substr(basename($file), 0, -14));
                $className = $namespace . Inflector::id2camel($id) . 'Controller';
                if (strpos($className, '-') === false && class_exists($className) && is_subclass_of($className, 'yii\base\Controller')) {
                    $controller = new $className($prefix . $id, $module);
                    self::getActionRoutes($controller, $result);
                    $result[] = '/' . $controller->uniqueId . '/*';
                }
            }
        }
    }

    /**
     * 
     * @param \yii\base\Controller $controller
     * @param Array $result all controller action.
     */
    private static function getActionRoutes($controller, &$result)
    {
        $prefix = '/' . $controller->uniqueId . '/';
        foreach ($controller->actions() as $id => $value) {
            $result[] = $prefix . $id;
        }
        $class = new ReflectionClass($controller);
        foreach ($class->getMethods() as $method) {
            $name = $method->getName();
            if ($method->isPublic() && !$method->isStatic() && strpos($name, 'action') === 0 && $name !== 'actions') {
                $result[] = $prefix . Inflector::camel2id(substr($name, 6));
            }
        }
    }

    public static function getSavedRoutes()
    {
        $key = static::buildKey(__METHOD__);
        if (($cache = Yii::$app->getCache()) === null || ($result = $cache->get($key)) === false) {
            $result = [];
            foreach (Yii::$app->getAuthManager()->getPermissions() as $name => $value) {
                if ($name[0] === '/' && substr($name, -1) != '*') {
                    $result[] = $name;
                }
            }
            if ($cache !== null) {
                $cache->set($key, $result, 0, new GroupDependency([
                    'group' => static::getGroup(static::AUTH_GROUP)
                ]));
            }
        }
        return $result;
    }

    /**
     * 
     * @param \yii\web\User $user
     */
    public static function getAssignedMenu($user)
    {
        $manager = \Yii::$app->getAuthManager();
        $routes = $filter1 = $filter2 = [];
        foreach ($manager->getPermissionsByUser($user->id) as $name => $value) {
            if ($name[0] === '/') {
                if (substr($name, -2) === '/*') {
                    $name = substr($name, 0, -1);
                }
                $routes[] = $name;
            }
        }
        $prefix = '\\';
        sort($routes);
        foreach ($routes as $route) {
            if (strpos($route, $prefix) !== 0) {
                if (substr($route, -1) === '/') {
                    $prefix = $route;
                    $filter1[] = $route . '%';
                } else {
                    $filter2[] = $route;
                }
            }
        }
        $assigned = [];
        $query = Menu::find()->select(['menu_id'])->asArray();
        if (count($filter2)) {
            $assigned = $query->where(['menu_route' => $filter2])->column();
        }
        if (count($filter1)) {
            $query->where('menu_route like :filter');
            foreach ($filter1 as $filter) {
                $assigned = array_merge($assigned, $query->params([':filter' => $filter])->column());
            }
        }
        $menus = Menu::find()->asArray()->indexBy('menu_id')->all();
        $assigned = static::requiredParent($assigned, $menus);
        return static::normalizeMenu($assigned, $menus);
    }

    private static function requiredParent($assigned, &$menus)
    {
        $parents = [];
        foreach ($assigned as $id) {
            $parent_id = $menus[$id]['menu_parent'];
            if ($parent_id !== null && !in_array($parent_id, $assigned) && !in_array($parent_id, $parents)) {
                $parents[] = $parent_id;
            }
        }
        if (count($parents)) {
            $assigned = static::requiredParent(array_merge($assigned, $parents), $menus);
        }
        return $assigned;
    }

    private static function normalizeMenu(&$assigned, &$menus, $parent = null)
    {
        $result = [];
        foreach ($assigned as $id) {
            $menu = $menus[$id];
            if ($menu['menu_parent'] == $parent) {
                $item = [
                    'label' => $menu['menu_name'],
                    'url' => empty($menu['menu_route']) ? '#' : [$menu['menu_route']],
                    'items' => static::normalizeMenu($assigned, $menus, $id)
                ];
                if (empty($item['items'])) {
                    unset($item['items']);
                }
                $result[] = $item;
            }
        }
        return $result;
    }

    public static function getGroup($group)
    {
        return md5(serialize([__CLASS__, $group]));
    }

    private static function buildKey($key)
    {
        return[
            __CLASS__,
            $key
        ];
    }

    public static function refeshFileCache()
    {
        if (($cache = Yii::$app->getCache()) !== null) {
            GroupDependency::invalidate($cache, static::getGroup(static::FILE_GROUP));
        }
    }

    public static function refeshAuthCache()
    {
        if (($cache = Yii::$app->getCache()) !== null) {
            GroupDependency::invalidate($cache, static::getGroup(static::AUTH_GROUP));
        }
    }
}