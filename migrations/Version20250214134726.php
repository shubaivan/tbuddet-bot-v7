<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Product;
use App\Migrations\Factory\MigrationInterface;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250214134726 extends AbstractMigration implements MigrationInterface
{
    private ContainerInterface $container;

    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $em = $this->container->get('doctrine.orm.default_entity_manager');
        $connection = $em->getConnection();

        $executeQuery = $connection->executeQuery('SELECT * FROM product');
        $allAssociative = $executeQuery->fetchAllAssociative();

        $this->addSql('ALTER TABLE product ALTER COLUMN price SET DEFAULT NULL');
        $this->addSql('update product set price = :price', ['price' => null]);
        $this->addSql('ALTER TABLE product ALTER price TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE product ALTER price TYPE jsonb USING price::jsonb');

        $this->addSql('ALTER TABLE product ALTER price SET DEFAULT \'{}\'');

        foreach ($allAssociative as $product) {
            $this->addSql(
                'update product set price = :price where id = :id',
                [
                    'price' => json_encode(['ua' => $product['price']]),
                    'id' => $product['id']
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE product ALTER price TYPE INT DEFAULT NULL');
    }

    public function setContainer(ContainerInterface $container = null): void
    {
        $this->container = $container;
    }
}
