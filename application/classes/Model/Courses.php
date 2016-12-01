<?php defined('SYSPATH') OR die('No Direct Script Access');

/**
 * Модель курсов, имеет поля, соответствующие полям в базе данных и статические методы для получения
 * контеста и массива контестов по некоторым признакам.
 *
 * @author     Alexander Menshikov
 */
Class Model_Courses extends Model
{
    public $id = 0;
    public $uri;
    public $title;
    public $text;
    public $description;
    public $cover;
    public $dt_create;
    public $dt_update;
    public $is_removed;
    public $is_published;
    public $marked;
    public $order;

    private $redis;

    /**
     * Пустой конструктор для модели курсов, если нужно получить курс из хранилища, нужно пользоваться статическими
     * методами
     */
    public function __construct()
    {
        $this->redis = Controller_Base_preDispatch::_redis();
    }

    /**
     * Добавляет текущий объект в базу данных и присваивает ему ID.
     *
     * @throws Kohana_Exception
     */
    public function insert()
    {
        $idAndRowAffected = Dao_Courses::insert()
            ->set('uri',            $this->uri)
            ->set('title',          $this->title)
            ->set('text',           $this->text)
            ->set('description',    $this->description)
            ->set('cover',          $this->cover)
            ->set('is_removed',     $this->is_removed)
            ->set('is_published',   $this->is_published)
            ->set('marked',         $this->marked)
            ->set('order',          $this->order)
            ->clearcache('courses_list')
            ->execute();

        if ($idAndRowAffected) {
            $course = Dao_Courses::select()
                ->where('id', '=', $idAndRowAffected)
                ->limit(1)
                ->cached(10*Date::MINUTE)
                ->execute();

            $this->fillByRow($course);

            $this->addToFeedList();
        }

        return $idAndRowAffected;
    }

    /**
     * Заполняет текущий объект строкой из базы данных.
     *
     * @param $course_row array строка из базы данных с создаваемым курсом
     * @return Model_Courses модель, заполненная полями из курса, либо пустая модель, если была передана пустая строка.
     */
    private function fillByRow($course_row)
    {
        if (empty($course_row['id'])) return $this;

        foreach ($course_row as $fieldname => $value) {
            if (property_exists($this, $fieldname)) $this->$fieldname = $value;
        }

        return $this;
    }


    /**
     * Удаляет курс, представленный в модели.
     *
     * @param $user_id Number идентификатор пользователя, для проверки прав на удаление курса
     */
    public function remove()
    {
        if ($this->id != 0) {

            Dao_Courses::update()->where('id', '=', $this->id)
                ->set('is_removed', 1)
                ->set('is_published', 0)
                ->clearcache('courses_list')
                ->execute();

            $this->remToFeedList();

            // Курс удален
            $this->id = 0;
        }
    }


    /**
     * Обновляет курс, сохраняя поля модели.
     */
    public function update()
    {
        Dao_Contests::update()->where('id', '=', $this->id)
            ->set('uri',            $this->uri)
            ->set('title',          $this->title)
            ->set('text',           $this->text)
            ->set('description',    $this->description)
            ->set('cover',          $this->cover)
            ->set('dt_update',      $this->dt_update)
            ->set('is_removed',     $this->is_removed)
            ->set('is_published',   $this->is_published)
            ->set('marked',         $this->marked)
            ->set('order',          $this->order)
            ->clearcache($this->id)
            ->execute();
    }

    /**
     * Добавляет статью к курсу.
     * @param $article_id
     * @param $course_id
     * @return DB::insert
     */
    public static function addArticleToCourse($article_id, $course_id) {
        $article = Model_Article::get($article_id);
        if (!$article->id) {
            return false;
        }

        $course = Model_Courses::get($course_id);
        if (!$course->id) {

            //Добавляем в фид id статьи, так как не передан курс, к которому ее добавить
            $article->addToFeedList();

            return false;
        }

        //Удаляем id статьи из фида
        $article->remFromFeedList();

        return DB::insert('Courses_articles', array('course_id', 'article_id', 'article_index'))->values(array($course_id, $article_id, 0))->execute();
    }

    /**
     * Получить список курсов, в которые включена данная статья.
     * @param $article
     * @return bool|object
     */
    public static function getCurrentCoursesIds($article) {
        if (!$article) {
            return false;
        }

        return DB::select('course_id')->from('Courses_articles')->where('article_id', '=', $article->id)->execute();
    }

    /**
     * Открепляет статью ото всех курсов.
     * TODO: временное решение, пока у нас статья может быть добавлена только к отдному курсу.
     * @param $article_id
     * @return DB:remove
     */
    public static function delArticleFromCourses($article_id) {

        //Добавляем id статьи в фид
        $article = Model_Article::get($article_id);
        $article->addToFeedList();

        return DB::delete('Courses_articles')->where('article_id', '=', $article_id)->execute();
    }

    /**
     * Возвращает курс из базы данных с указанным идентификатором, иначе возвращает пустой курс с ID=0.
     *
     * @param int $id идентификатор курса в базе
     * @return Model_Courses экземпляр модели с указанным идентификатором и заполненными полями, если найден в базе или
     * пустую модель с ID=0.
     */
    public static function get($id = 0, $needClearCache = false)
    {
        $course = Dao_Courses::select()
            ->where('id', '=', $id)
            ->limit(1);

        if ($needClearCache) {
            $course->clearcache($id);
        } else {
            $course->cached(Date::MINUTE * 5, $id);
        }

        $course = $course->execute();

        $model = new Model_Courses();
        return $model->fillByRow($course);
    }

    /**
     * Получить все активные (опубликованные и не удалённые курсы) в порядке убывания ID.
     */
    public static function getActiveCourses($clearCache = false)
    {
        return Model_Courses::getCourses(false, false, !$clearCache ? Date::MINUTE * 5 : null);
    }


    /**
     * Получить все неудалённые курсы в порядке убывания ID.
     */
    public static function getAllCourses()
    {
        return Model_Courses::getCourses(true, false);
    }

    public static function getActiveCoursesNames()
    {
        $courses = Model_Courses::getActiveCourses();
        $list = array();
        foreach ($courses as $course) {
            $list []= array('id' => $course->id, 'name' => $course->title);
        }
        return $list;
    }

    /**
     * Получить список курсов с указанными условиями.
     *
     * @param $add_removed boolean добавлять ли удалённые курсы в получаемый список курсов
     * @param $add_not_published boolean
     * @return array ModelCourses массив моделей, удовлетворяющих запросу
     */
    private static function getCourses($add_not_published = false, $add_removed = false, $cachedTime = null)
    {
        $coursesQuery = Dao_Courses::select()->limit(200);        // TODO add pagination.

        if (!$add_removed) {
            $coursesQuery->where('is_removed', '=', 0);
        }

        if (!$add_not_published) {
            $coursesQuery->where('is_published', '=', 1);
        }

        if ($cachedTime) {
            $coursesQuery->cached($cachedTime, 'courses_list');
        }
        $course_rows = $coursesQuery->order_by('dt_create', 'DESC')->execute();

        return self::rowsToModels($course_rows);
    }

    private static function rowsToModels($course_rows)
    {
        $courses = array();

        if (!empty($course_rows)) {
            foreach ($course_rows as $course_row) {

                $course = new Model_Courses();
                $course->fillByRow($course_row);
                array_push($courses, $course);
            }
        }

        return $courses;
    }

    /**
     * Получить id статей, входящих в курс
     *
     * @param $course_id
     * @return bool|object
     */
    public static function getArticles($course_id)
    {
        if (!$course_id) {
            return false;
        }

        return DB::select('article_id')->from('Courses_articles')->where('course_id', '=', $course_id)->execute();
    }

    /**
     * Меняем порядок элементов в фиде (для курсов)
     *
     * @param string $next_item - member для sorted set в Redis (то есть article:<id> или course:<id>')
     * TODO: реализовать автоматическое определение типа следующего элемента (статьи или курса)
     * @return bool
     */
    public function changeFeedOrder($next_item)
    {

        if ($this->redis->zRank('feed', 'course:'.$this->id === false)) {
            return false;
        }

        if($this->redis->zRank('feed', $next_item) === false) {
            return false;
        }

        $interval = $this->redis->zScore('feed', $next_item) - $this->redis->zScore('feed', 'course:'.$this->id);

        $this->redis->zIncrBy('feed', $interval + 1, 'course:'.$this->id);

    }

    /*
     * Добавляем курс в фид в Redis
     */
    public function addToFeedList()
    {
        if ($this->redis->zRank('feed', 'course:'.$this->id) === false)
            $this->redis->zAdd('feed', time(), 'course:'.$this->id);
    }

    /**
     * Удаляем курс из фида
     */
    public function remFromFeedList()
    {
        $this->redis->zRem('feed', 'course:'.$this->id);
    }

}
