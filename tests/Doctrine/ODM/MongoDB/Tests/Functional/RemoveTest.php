<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests\Functional;

use DateTime;
use Doctrine\ODM\MongoDB\Tests\BaseTest;
use Documents\Account;
use Documents\BlogPost;
use Documents\CmsArticle;
use Documents\CmsComment;
use Documents\Comment;
use Documents\Developer;
use Documents\Group;
use Documents\Project;
use Documents\User;

class RemoveTest extends BaseTest
{
    public function testRemove(): void
    {
        $account = new Account();
        $account->setName('Jon Test Account');

        $user = new User();
        $user->setUsername('jon');
        $user->setPassword('changeme');
        $user->setAccount($account);

        $this->dm->persist($user);
        $this->dm->flush();

        $this->dm->remove($user);
        $this->dm->flush();

        $account = $this->dm->find(Account::class, $account->getId());
        self::assertNull($account);

        $user = $this->dm->find(User::class, $user->getId());
        self::assertNull($user);
    }

    public function testUnsetFromEmbeddedCollection(): void
    {
        $userRepository = $this->dm->getRepository(User::class);

        $user = new User();
        $user->addGroup(new Group('group1'));
        $user->addGroup(new Group('group2'));
        $user->addGroup(new Group('group3'));

        $this->dm->persist($user);
        $this->dm->flush();
        $this->dm->clear();

        self::assertCount(3, $user->getGroups());

        $user = $userRepository->find($user->getId());
        $user->getGroups()->remove(0);

        $this->dm->flush();
        $this->dm->clear();

        $user = $userRepository->find($user->getId());

        self::assertCount(2, $user->getGroups());
    }

    public function testUnsetFromReferencedCollectionWithCascade(): void
    {
        $developerRepository = $this->dm->getRepository(Developer::class);
        $projectRepository   = $this->dm->getRepository(Project::class);

        // Developer owns the one-to-many relationship and cascades
        $developer = new Developer('developer');
        $project1  = new Project('project1');
        $project2  = new Project('project2');

        $developer->getProjects()->add($project1);
        $developer->getProjects()->add($project2);

        $this->dm->persist($developer);
        $this->dm->flush();
        $this->dm->clear();

        $developer = $developerRepository->find($developer->getId());
        $project1  = $projectRepository->find($project1->getId());
        $project2  = $projectRepository->find($project2->getId());

        // Persist is cascaded
        self::assertNotNull($developer);
        self::assertCount(2, $developer->getProjects());
        self::assertNotNull($project1);
        self::assertNotNull($project2);

        $developer->getProjects()->remove(0);

        $this->dm->flush();
        $this->dm->clear();

        $developer = $developerRepository->find($developer->getId());
        $project1  = $projectRepository->find($project1->getId());
        $project2  = $projectRepository->find($project2->getId());

        // Removing owner's reference does not cause referenced document to be removed
        self::assertNotNull($developer);
        self::assertCount(1, $developer->getProjects());
        self::assertNotNull($project1);
        self::assertNotNull($project2);

        $this->dm->remove($developer);

        $this->dm->flush();
        $this->dm->clear();

        $developer = $developerRepository->find($developer->getId());
        $project1  = $projectRepository->find($project1->getId());
        $project2  = $projectRepository->find($project2->getId());

        // Remove cascades to referenced documents
        self::assertNull($developer);
        self::assertNotNull($project1);
        self::assertNull($project2);
    }

    public function testUnsetFromReferencedCollectionWithoutCascade(): void
    {
        $articleRepository = $this->dm->getRepository(CmsArticle::class);
        $commentRepository = $this->dm->getRepository(CmsComment::class);

        // CmsArticle owns the one-to-many relationship but does not cascade
        $article  = new CmsArticle();
        $comment1 = new CmsComment();
        $comment2 = new CmsComment();

        $article->addComment($comment1);
        $article->addComment($comment2);

        /* Note: if we don't persist the CmsComments, CmsArticle's will create a
         * collection of two DBRefs with null $id values. Later on, this data is
         * used to initialize the PersistentCollection's mongoData property, and
         * leads to odd behavior (e.g. count is 2, but after unsetting the first
         * element, count becomes 0).
         */
        $this->dm->persist($article);
        $this->dm->persist($comment1);
        $this->dm->persist($comment2);
        $this->dm->flush();
        $this->dm->clear();

        $article  = $articleRepository->find($article->id);
        $comment1 = $commentRepository->find($comment1->id);
        $comment2 = $commentRepository->find($comment2->id);

        unset($article->comments[0]);

        $this->dm->flush();
        $this->dm->clear();

        $article  = $articleRepository->find($article->id);
        $comment1 = $commentRepository->find($comment1->id);
        $comment2 = $commentRepository->find($comment2->id);

        // Removing reference on owner does not cause referenced document to be removed
        self::assertNotNull($article);
        self::assertCount(1, $article->comments);
        self::assertNotNull($comment1);
        self::assertNotNull($comment2);

        $this->dm->remove($article);

        $this->dm->flush();
        $this->dm->clear();

        $article  = $articleRepository->find($article->id);
        $comment1 = $commentRepository->find($comment1->id);
        $comment2 = $commentRepository->find($comment2->id);

        // Remove does not cascade to referenced documents
        self::assertNull($article);
        self::assertNotNull($comment1);
        self::assertNotNull($comment2);
    }

    public function testUnsetFromReferencedCollectionWithCascadeAndMappedBy(): void
    {
        $blogPostRepository = $this->dm->getRepository(BlogPost::class);
        $commentRepository  = $this->dm->getRepository(Comment::class);

        /* CmsComment owns the one-to-many relationship, since BlogPost uses
         * mappedBy. Both sides cascade operations.
         */
        $blogPost = new BlogPost();
        $comment1 = new Comment('comment1', new DateTime());
        $comment2 = new Comment('comment2', new DateTime());

        $blogPost->addComment($comment1);
        $blogPost->addComment($comment2);

        $this->dm->persist($blogPost);
        $this->dm->flush();
        $this->dm->clear();

        $blogPost = $blogPostRepository->find($blogPost->id);
        $comment1 = $commentRepository->find($comment1->id);
        $comment2 = $commentRepository->find($comment2->id);

        // Persist is cascaded
        self::assertNotNull($blogPost);
        self::assertCount(2, $blogPost->comments);
        self::assertNotNull($comment1);
        self::assertNotNull($comment2);

        unset($blogPost->comments[0]);

        $this->dm->flush();
        $this->dm->clear();

        $blogPost = $blogPostRepository->find($blogPost->id);
        $comment1 = $commentRepository->find($comment1->id);
        $comment2 = $commentRepository->find($comment2->id);

        // Non-owning side of mappedBy reference is immutable
        self::assertNotNull($blogPost);
        self::assertCount(2, $blogPost->comments);
        self::assertNotNull($comment1);
        self::assertNotNull($comment2);

        $this->dm->remove($blogPost);

        $this->dm->flush();
        $this->dm->clear();

        $blogPost = $blogPostRepository->find($blogPost->id);
        $comment1 = $commentRepository->find($comment1->id);
        $comment2 = $commentRepository->find($comment2->id);

        // Remove cascades to referenced documents
        self::assertNull($blogPost);
        self::assertNull($comment1);
        self::assertNull($comment2);
    }
}
