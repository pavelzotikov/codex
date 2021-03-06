<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Courses_Modify extends Controller_Base_preDispatch
{
    /**
     * this method prevent no admin users visit /course/add, /course/<course_id>/edit
     */
    public function before()
    {
        parent::before();
        if (!$this->user->checkAccess(array(Model_User::ROLE_ADMIN)))
            $this->redirect('/');
    }

    public function action_save()
    {
        $csrfToken = Arr::get($_POST, 'csrf');

        /*
         * редактирование происходит напрямую из роута вида: <controller>/<action>/<id>
         * так как срабатывает обычный роут, то при отправке формы передается переменная course_id.
         * Форма отправляет POST запрос
         */
        if ($this->request->post() ){
            $course_id = Arr::get($_POST, 'course_id');
            $course = Model_Courses::get($course_id, true);
        }
        /*
         * Редактирование через Алиас
         * Здесь сперва запрос получает Controller_Uri, которая будет передавать id сущности через query('id')
         */
        else if ($course_id = $this->request->param('id') ?: $this->request->query('id')) {
            $course = Model_Courses::get($course_id);
        } else {
            $course = new Model_Courses();
        }

        $feed = new Model_Feed($course::FEED_TYPE);

        if (Security::check($csrfToken)) {
            $course->title          = Arr::get($_POST, 'title');
            $course->text           = Arr::get($_POST, 'course_text');
            $course->cover          = Arr::get($_POST, 'cover');
            $course->description    = Arr::get($_POST, 'description');
            $course->marked         = Arr::get($_POST, 'marked', '0');
            $course->is_published   = Arr::get($_POST, 'is_published', '0');
            $course->is_removed     = Arr::get($_POST, 'is_removed', '0');
            $course->dt_close       = Arr::get($_POST, 'duration');
            $course->order          = Arr::get($_POST, 'order');
            $course->uri            = Arr::get($_POST, 'uri');

            /**
             * @var string $item_below_key
             * Ключ элемента в фиде, над которым нужно поставить данную статью ('[article|course]:<id>')
             * */
            $item_below_key         = Arr::get($_POST, 'item_below_key', 0);

            $uri = Arr::get($_POST, 'uri');
            $alias = Model_Alias::generateUri($uri);

            if ($course->title && $course->text && $course->description) {

                if ($course_id) {
                    $course->dt_update = date('Y-m-d H:i:s');
                    $course->uri = Model_Alias::updateAlias($course->uri, $alias, Model_Uri::COURSE, $course_id);
                    $course->update();
                } else {
                    $insertedId   = $course->insert();
                    $course->uri = Model_Alias::addAlias($alias, Model_Uri::COURSE, $insertedId);
                }

                if ($course->is_published && !$course->is_removed) {
                    //Добавляем курс в фид
                    $feed->add($course->id, $course->dt_create);

                    //Ставим курс в переданное место в фиде, если это было указано
                    if ($item_below_key) {
                        $feed->putAbove($course->id, $item_below_key);
                    }
                } else {
                    $feed->remove($course->id);
                }


                // Если поле uri пустое, то редиректить на обычный роут /course/id
                $redirect = ($uri) ? $course->uri : '/course/' . $course->id;
                $this->redirect( $redirect );

            } else {
                $this->view['error'] = true;
            }
        }
        $this->view['course'] = $course;
        $this->view['topFeed'] = $feed->get(5);
        $this->template->content = View::factory('templates/courses/create', $this->view);
    }

    public function action_delete()
    {
        $user_id = $this->user->id;
        $course_id = $this->request->param('course_id') ?: $this->request->query('id');

        if (!empty($course_id) && !empty($user_id))
        {
            $course = Model_Courses::get($course_id);
            $course->remove();

            $feed = new Model_Feed($course::FEED_TYPE);
            $feed->remove($course->id);
        }

        $this->redirect('/admin/courses');
    }

}
