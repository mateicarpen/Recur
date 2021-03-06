<?php

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Form\TaskType;
use App\Repository\TaskLogRepository;
use App\Repository\TaskRepository;
use App\Services\TaskManager;
use App\Services\TodoPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class TasksController extends Controller
{
    /**
     * @var TaskRepository
     */
    private $taskRepo;

    /**
     * @var TaskManager
     */
    private $taskManager;

    /**
     * @var User
     */
    private $currentUser;


    public function __construct(
        TaskRepository $taskRepo,
        TaskManager $taskManager,
        TokenStorageInterface $tokenStorage
    ) {
        $this->taskRepo = $taskRepo;
        $this->taskManager = $taskManager;
        $this->currentUser = $tokenStorage->getToken()->getUser();
    }


    public function index()
    {
        $task = $this->taskRepo->findAllByUser($this->getUser());

        return $this->render('tasks/index.html.twig', [
            'tasks' => $task,
        ]);
    }


    public function create(Request $request)
    {
        $task = new Task;

        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->taskManager->store($form->getData());

            return $this->redirectToRoute('tasks_index');
        }

        return $this->render('tasks/form.html.twig', [
            'title' => 'Create task',
            'form' => $form->createView()
        ]);
    }


    public function edit(int $id, Request $request)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser());

        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->taskManager->update($form->getData());

            return $this->redirectToRoute('tasks_index');
        }

        return $this->render('tasks/form.html.twig', [
            'title' => 'Edit task',
            'form' => $form->createView(),
        ]);
    }


    public function delete(int $id, EntityManagerInterface $em)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser());

        $this->taskManager->delete($task);

        return $this->redirectToRoute('tasks_index');
    }


    public function todo(TodoPlanner $todoPlanner)
    {
        $tasksDueToday = $todoPlanner->getTasksDueToday($this->currentUser);
        $tasksDueTomorrow = $todoPlanner->getTasksDueTomorrow($tasksDueToday, $this->currentUser);
        $tasksDueNextDays = $todoPlanner->getTasksDueNextDays($tasksDueToday, $tasksDueTomorrow, $this->currentUser);

        return $this->render('tasks/todos.html.twig', [
            'tasksDueToday' => $tasksDueToday,
            'tasksDueTomorrow' => $tasksDueTomorrow,
            'tasksDueNextDays' => $tasksDueNextDays,
        ]);
    }


    public function complete($id)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser()); // exception if not found

        $this->taskManager->complete($task);

        $undoUrl = $this->generateUrl('tasks_undo', ['id' => $task->getId()]);
        $flashMessage = "Task completed. <a href='{$undoUrl}'>Undo completion.</a>";
        $this->addFlash('success', $flashMessage);

        return $this->redirectToRoute('todo');
    }


    public function undo($id, TaskLogRepository $logRepo)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser()); // exception if not found

        $this->taskManager->undoCompletion($task, $logRepo);

        return $this->redirectToRoute('todo');
    }


    public function logs(int $id, TaskLogRepository $logRepo)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser()); // exception if not found
        $logs = $logRepo->findByTask($task);

        return $this->render('tasks/logs.html.twig', [
            'task' => $task,
            'logs' => $logs,
        ]);
    }


    public function history(TaskLogRepository $logRepo)
    {
        $logs = $logRepo->findAllDescending($this->getUser());

        return $this->render('tasks/history.html.twig', [
            'logs' => $logs
        ]);
    }
}