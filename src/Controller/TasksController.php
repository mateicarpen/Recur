<?php

namespace App\Controller;

use App\Entity\FrequencyUnit;
use App\Entity\Task;
use App\Entity\TaskLog;
use App\Repository\FrequencyUnitRepository;
use App\Repository\TaskLogRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;

class TasksController extends Controller
{
    /**
     * @var TaskRepository
     */
    private $taskRepo;

    public function __construct(TaskRepository $taskRepo)
    {
        $this->taskRepo = $taskRepo;
    }

    public function todo()
    {
        // TODO: ma asigur ca nu o sa fie probleme cu datele (sa fie toate despre ora 00 - cred)

        $tasksDueToday = $this->getTasksDueToday();
        $tasksDueTomorrow = $this->getTasksDueTomorrow($tasksDueToday);
        $tasksDueNextDays = $this->getTasksDueNextDays($tasksDueToday, $tasksDueTomorrow);

        return $this->render('tasks/todos.html.twig', [
            'tasksDueToday' => $tasksDueToday,
            'tasksDueTomorrow' => $tasksDueTomorrow,
            'tasksDueNextDays' => $tasksDueNextDays,
        ]);
    }

    public function complete($id, EntityManagerInterface $em)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser()); // exception if not found

        $now = new \DateTime();
        $task->setLastCompleted($now);

        $log = new TaskLog();
        $log->setTask($task);
        $log->setCreateDate($now);

        $em->persist($log);
        $em->flush();

        $undoUrl = $this->generateUrl('todo_undo', ['id' => $task->getId()]);
        $flashMessage = "Task completed. <a href='{$undoUrl}'>Undo completion.</a>";
        $this->addFlash('success', $flashMessage);

        return $this->redirectToRoute('todo');
    }

    public function undo($id, TaskLogRepository $logRepo, EntityManagerInterface $em)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser()); // exception if not found

        $fiveMinutesAgo = new \DateTime('-5 minutes');

        if (is_null($task->getLastCompleted()) || $task->getLastCompleted() < $fiveMinutesAgo) {
            return $this->redirectToRoute('todo');
        }

        $taskLogs = $logRepo->findByTask($task, 2);

        $em->remove($taskLogs[0]); // remove freshly inserted log

        if (!empty($taskLogs[1])) {
            $task->setLastCompleted($taskLogs[1]->getCreateDate());
        } else {
            $task->setLastCompleted(null);
        }

        $em->flush();

        return $this->redirectToRoute('todo');
    }

    public function index()
    {
        $task = $this->taskRepo->findAllByUser($this->getUser());

        return $this->render('tasks/index.html.twig', [
            'tasks' => $task,
        ]);
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

    public function create(Request $request, EntityManagerInterface $em)
    {
        $task = new Task;
        $form = $this->getForm($task);
        $form->handleRequest($request);

        // validation
        if ($form->isSubmitted() && $form->isValid()) {
            $task = $form->getData();

            $task->setUser($this->getUser());
            $task->setCreateDate(new \DateTime()); // TODO: move from here wtf
            $task->setUpdateDate(new \DateTime());

            $em->persist($task);
            $em->flush();

            return $this->redirectToRoute('tasks_index');
        }

        return $this->render('tasks/form.html.twig', [
            'title' => 'Create task',
            'form' => $form->createView()
        ]);
    }

    public function edit(int $id, Request $request, EntityManagerInterface $em)
    {
        $task = $this->taskRepo->findByUser($id, $this->getUser());

        $form = $this->getForm($task);
        $form->handleRequest($request);

        // validation
        if ($form->isSubmitted() && $form->isValid()) {
            $task = $form->getData();

            $task->setUpdateDate(new \DateTime());

            $em->flush();

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

        $em->remove($task);
        $em->flush();

        return $this->redirectToRoute('tasks_index');
    }

    private function getForm(Task $task = null) // TODO: Form file
    {
        return $this->createFormBuilder($task, [
            'method' => 'POST'
        ])
        ->add('name', TextType::class)
        ->add('frequency', IntegerType::class)
        ->add('frequencyUnit', EntityType::class, [
            'class' => FrequencyUnit::class,
            'choice_label' => 'name'
        ])
        ->add('startDate', DateType::class, [
            'widget' => 'single_text',
        ])
        ->add('adjustOnCompletion', ChoiceType::class, [
            'choices' => [
                'Yes' => true,
                'No' => false
            ],
            'expanded' => true,
        ])
        ->getForm();
    }

    /**
     * @return Task[]
     */
    private function getTasksDueToday(): array
    {
        $today = new \DateTime;

        return $this->getTasksDue($today);
    }

    /**
     * @param Task[] $tasksDueToday
     * @return Task[]
     */
    private function getTasksDueTomorrow(array $tasksDueToday): array
    {
        $tomorrow = new \DateTime('+1 day');

        $tasks = $this->getTasksDue($tomorrow);

        foreach ($tasks as $key => $task) {
            if (in_array($task, $tasksDueToday)) {
                unset($tasks[$key]);
            }
        }

        return $tasks;
    }

    /**
     * @param Task[] $tasksDueToday
     * @param Task[] $tasksDueTomorrow
     * @return Task[]
     */
    private function getTasksDueNextDays(array $tasksDueToday, array $tasksDueTomorrow): array
    {
        $date = new \DateTime('+6 days');

        $tasks = $this->getTasksDue($date);
        $previousTasks = array_merge($tasksDueToday, $tasksDueTomorrow);

        foreach ($tasks as $key => $task) {
            if (in_array($task, $previousTasks)) {
                unset($tasks[$key]);
            }
        }

        return $tasks;
    }

    /**
     * @param \DateTime $date
     * @return Task[]
     */
    private function getTasksDue(\DateTime $date): array
    {
        $tasks = $this->taskRepo->findStartedEarlierThan($date, $this->getUser());

        $todos = [];
        foreach ($tasks as $task) {
            /** @var $task Task */

            if (is_null($task->getLastCompleted())) {
                $todos[] = $task;
                continue;
            }

            $dueDate = $this->getTaskDueDate($task);

            if ($dueDate <= $date && $task->getLastCompleted() < $dueDate) {
                $todos[] = $task;
            }
        }

        return $todos;
    }

    private function getTaskDueDate(Task $task): \DateTime
    {
        switch ($task->getFrequencyUnit()->getId()) {
            case FrequencyUnit::DAY:
                $periodString = 'D';
                break;

            case FrequencyUnit::WEEK:
                $periodString = 'W';
                break;

            case FrequencyUnit::MONTH:
                $periodString = 'M';
                break;
        }

        $interval = new \DateInterval('P' . $task->getFrequency() . $periodString);

        $adjustOnCompletion = $task->getAdjustOnCompletion();
        if ($adjustOnCompletion) {
            $date = clone $task->getLastCompleted();

            return $date->add($interval);
        } else {
            $date = clone $task->getStartDate();
            $currentDate = new \DateTime('today');

            while($date < $currentDate) {
                $dueDate = clone $date;

                $date->add($interval);
            }

            return $dueDate;
        }

    }
}